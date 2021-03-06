<?php

namespace WellRESTed\Test\Unit\Routing;

use Prophecy\Argument;
use WellRESTed\Routing\MethodMap;

/**
 * @coversDefaultClass WellRESTed\Routing\MethodMap
 * @uses WellRESTed\Routing\MethodMap
 * @uses WellRESTed\Dispatching\Dispatcher
 * @group routing
 */
class MethodMapTest extends \PHPUnit_Framework_TestCase
{
    private $dispatcher;
    private $request;
    private $response;
    private $next;
    private $middleware;

    public function setUp()
    {
        $this->request = $this->prophesize('Psr\Http\Message\ServerRequestInterface');
        $this->response = $this->prophesize('Psr\Http\Message\ResponseInterface');
        $this->response->withStatus(Argument::any())->willReturn($this->response->reveal());
        $this->response->withHeader(Argument::cetera())->willReturn($this->response->reveal());
        $this->next = function ($request, $response) {
            return $response;
        };
        $this->middleware = $this->prophesize('WellRESTed\MiddlewareInterface');
        $this->middleware->__invoke(Argument::cetera())->willReturn();
        $this->dispatcher = $this->prophesize('WellRESTed\Dispatching\DispatcherInterface');
        $this->dispatcher->dispatch(Argument::cetera())->will(
            function ($args) {
                list($middleware, $request, $response, $next) = $args;
                return $middleware($request, $response, $next);
            }
        );
    }

    /**
     * @covers ::__construct
     */
    public function testCreatesInstance()
    {
        $methodMap = new MethodMap($this->dispatcher->reveal());
        $this->assertNotNull($methodMap);
    }

    /**
     * @covers ::__invoke
     * @covers ::dispatchMiddleware
     * @covers ::register
     */
    public function testDispatchesMiddlewareWithMatchingMethod()
    {
        $this->request->getMethod()->willReturn("GET");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->middleware->__invoke(
            $this->request->reveal(),
            $this->response->reveal(),
            $this->next
        )->shouldHaveBeenCalled();
    }

    /**
     * @coversNothing
     */
    public function testTreatsMethodNamesCaseSensitively()
    {
        $this->request->getMethod()->willReturn("get");

        $middlewareUpper = $this->prophesize('WellRESTed\MiddlewareInterface');
        $middlewareUpper->__invoke(Argument::cetera())->willReturn();

        $middlewareLower = $this->prophesize('WellRESTed\MiddlewareInterface');
        $middlewareLower->__invoke(Argument::cetera())->willReturn();

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $middlewareUpper->reveal());
        $map->register("get", $middlewareLower->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $middlewareLower->__invoke(
            $this->request->reveal(),
            $this->response->reveal(),
            $this->next
        )->shouldHaveBeenCalled();
    }

    /**
     * @covers ::__invoke
     * @covers ::dispatchMiddleware
     * @covers ::register
     */
    public function testDispatchesWildcardMiddlewareWithNonMatchingMethod()
    {
        $this->request->getMethod()->willReturn("GET");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("*", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->middleware->__invoke(
            $this->request->reveal(),
            $this->response->reveal(),
            $this->next
        )->shouldHaveBeenCalled();
    }

    /**
     * @covers ::__invoke
     */
    public function testDispatchesGetMiddlewareForHeadByDefault()
    {
        $this->request->getMethod()->willReturn("HEAD");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->middleware->__invoke(
            $this->request->reveal(),
            $this->response->reveal(),
            $this->next
        )->shouldHaveBeenCalled();
    }

    /**
     * @covers ::register
     */
    public function testRegistersMiddlewareForMultipleMethods()
    {
        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET,POST", $this->middleware->reveal());

        $this->request->getMethod()->willReturn("GET");
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->request->getMethod()->willReturn("POST");
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->middleware->__invoke(
            $this->request->reveal(),
            $this->response->reveal(),
            $this->next
        )->shouldHaveBeenCalledTimes(2);
    }

    public function testSettingNullUnregistersMiddleware()
    {
        $this->request->getMethod()->willReturn("POST");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("POST", $this->middleware->reveal());
        $map->register("POST", null);
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->response->withStatus(405)->shouldHaveBeenCalled();
    }

    /**
     * @covers ::__invoke
     * @covers ::addAllowHeader
     * @covers ::getAllowedMethods
     */
    public function testSetsStatusTo200ForOptions()
    {
        $this->request->getMethod()->willReturn("OPTIONS");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->response->withStatus(200)->shouldHaveBeenCalled();
    }

    public function testStopsPropagatingAfterOptions()
    {
        $calledNext = false;
        $next = function ($request, $response) use (&$calledNext) {
            $calledNext = true;
            return $response;
        };

        $this->request->getMethod()->willReturn("OPTIONS");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $next);

        $this->assertFalse($calledNext);
    }

    /**
     * @covers ::__invoke
     * @covers ::addAllowHeader
     * @covers ::getAllowedMethods
     * @dataProvider allowedMethodProvider
     */
    public function testSetsAllowHeaderForOptions($methodsDeclared, $methodsAllowed)
    {
        $this->request->getMethod()->willReturn("OPTIONS");

        $map = new MethodMap($this->dispatcher->reveal());
        foreach ($methodsDeclared as $method) {
            $map->register($method, $this->middleware->reveal());
        }
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $containsAllMethods = function ($headerValue) use ($methodsAllowed) {
            foreach ($methodsAllowed as $method) {
                if (strpos($headerValue, $method) === false) {
                    return false;
                }
            }
            return true;
        };
        $this->response->withHeader("Allow", Argument::that($containsAllMethods))->shouldHaveBeenCalled();
    }

    /**
     * @covers ::__invoke
     * @covers ::addAllowHeader
     * @covers ::getAllowedMethods
     * @dataProvider allowedMethodProvider
     */
    public function testSetsStatusTo405ForBadMethod()
    {
        $this->request->getMethod()->willReturn("POST");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $this->response->withStatus(405)->shouldHaveBeenCalled();
    }

    /**
     * @coversNothing
     * @dataProvider allowedMethodProvider
     */
    public function testStopsPropagatingAfterBadMethod()
    {
        $calledNext = false;
        $next = function ($request, $response) use (&$calledNext) {
            $calledNext = true;
            return $response;
        };
        $this->request->getMethod()->willReturn("POST");

        $map = new MethodMap($this->dispatcher->reveal());
        $map->register("GET", $this->middleware->reveal());
        $map($this->request->reveal(), $this->response->reveal(), $next);
        $this->assertFalse($calledNext);
    }

    /**
     * @covers ::__invoke
     * @covers ::addAllowHeader
     * @covers ::getAllowedMethods
     * @dataProvider allowedMethodProvider
     */
    public function testSetsAllowHeaderForBadMethod($methodsDeclared, $methodsAllowed)
    {
        $this->request->getMethod()->willReturn("BAD");

        $map = new MethodMap($this->dispatcher->reveal());
        foreach ($methodsDeclared as $method) {
            $map->register($method, $this->middleware->reveal());
        }
        $map($this->request->reveal(), $this->response->reveal(), $this->next);

        $containsAllMethods = function ($headerValue) use ($methodsAllowed) {
            foreach ($methodsAllowed as $method) {
                if (strpos($headerValue, $method) === false) {
                    return false;
                }
            }
            return true;
        };
        $this->response->withHeader("Allow", Argument::that($containsAllMethods))->shouldHaveBeenCalled();
    }

    public function allowedMethodProvider()
    {
        return [
            [["GET"], ["GET", "HEAD", "OPTIONS"]],
            [["GET", "POST"], ["GET", "POST", "HEAD", "OPTIONS"]],
            [["POST"], ["POST", "OPTIONS"]],
            [["POST"], ["POST", "OPTIONS"]],
            [["GET", "PUT,DELETE"], ["GET", "PUT", "DELETE", "HEAD", "OPTIONS"]],
        ];
    }
}
