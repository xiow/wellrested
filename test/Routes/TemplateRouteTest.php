<?php

namespace pjdietz\WellRESTed\Test;

use pjdietz\WellRESTed\Routes\TemplateRoute;
use Prophecy\Argument;

/**
 * @covers pjdietz\WellRESTed\Routes\TemplateRoute
 */
class TemplateRouteTest extends \PHPUnit_Framework_TestCase
{
    private $handler;
    private $request;
    private $response;

    /**
     * @dataProvider matchingTemplateProvider
     */
    public function testMatchesTemplate($template, $default, $vars, $path)
    {
        $this->request->getPath()->willReturn($path);
        $route = new TemplateRoute($template, $this->handler->reveal(), $default, $vars);
        $resp = $route->getResponse($this->request->reveal());
        $this->assertNotNull($resp);
    }

    /**
     * @dataProvider matchingTemplateProvider
     */
    public function testExtractsCaptures($template, $default, $vars, $path, $expectedCaptures)
    {
        $this->request->getPath()->willReturn($path);
        $route = new TemplateRoute($template, $this->handler->reveal(), $default, $vars);
        $route->getResponse($this->request->reveal());
        $this->handler->getResponse(
            Argument::any(),
            Argument::that(
                function ($args) use ($expectedCaptures) {
                    return count(array_diff_assoc($expectedCaptures, $args)) === 0;
                }
            )
        )->shouldHaveBeenCalled();
    }

    public function matchingTemplateProvider()
    {
        return [
            ["/cat/{id}", TemplateRoute::RE_NUM, null, "/cat/12", ["id" => "12"]],
            [
                "/cat/{catId}/{dogId}",
                TemplateRoute::RE_SLUG,
                null,
                "/cat/molly/bear",
                [
                    "catId" => "molly",
                    "dogId" => "bear"
                ]
            ],
            [
                "/cat/{catId}/{dogId}",
                TemplateRoute::RE_NUM,
                [
                    "catId" => TemplateRoute::RE_SLUG,
                    "dogId" => TemplateRoute::RE_SLUG
                ],
                "/cat/molly/bear",
                [
                    "catId" => "molly",
                    "dogId" => "bear"
                ]
            ],
            [
                "/cat/{catId}/{dogId}",
                TemplateRoute::RE_NUM,
                (object) [
                    "catId" => TemplateRoute::RE_SLUG,
                    "dogId" => TemplateRoute::RE_SLUG
                ],
                "/cat/molly/bear",
                [
                    "catId" => "molly",
                    "dogId" => "bear"
                ]
            ],
            ["/cat/{id}/*", null, null, "/cat/12/molly", ["id" => "12"]],
            [
                "/cat/{id}-{width}x{height}.jpg",
                TemplateRoute::RE_NUM,
                null,
                "/cat/17-200x100.jpg",
                [
                    "id" => "17",
                    "width" => "200",
                    "height" => "100"
                ]
            ],
            ["/cat/{path}", ".*", null, "/cat/this/section/has/slashes", ["path" => "this/section/has/slashes"]]
        ];
    }

    public function setUp()
    {
        $this->request = $this->prophesize("\\pjdietz\\WellRESTed\\Interfaces\\RequestInterface");
        $this->response = $this->prophesize("\\pjdietz\\WellRESTed\\Interfaces\\ResponseInterface");
        $this->handler = $this->prophesize("\\pjdietz\\WellRESTed\\Interfaces\\HandlerInterface");
        $this->handler->getResponse(Argument::cetera())->willReturn($this->response->reveal());
    }

    /**
     * @dataProvider nonmatchingTemplateProvider
     */
    public function testSkipNonmatchingTemplate($template, $default, $vars, $path)
    {
        $this->request->getPath()->willReturn($path);
        $route = new TemplateRoute($template, $this->handler->reveal(), $default, $vars);
        $resp = $route->getResponse($this->request->reveal());
        $this->assertNull($resp);
    }

    public function nonmatchingTemplateProvider()
    {
        return array(
            array("/cat/{id}", TemplateRoute::RE_NUM, null, "/cat/molly"),
            array("/cat/{catId}/{dogId}", TemplateRoute::RE_ALPHA, null, "/cat/12/13"),
            array(
                "/cat/{catId}/{dogId}",
                TemplateRoute::RE_NUM,
                array(
                    "catId" => TemplateRoute::RE_ALPHA,
                    "dogId" => TemplateRoute::RE_ALPHA
                ),
                "/cat/12/13"
            )
        );
    }
}
