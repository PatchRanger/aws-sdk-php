<?php
namespace Aws\Test;

use Aws\Api\ApiProvider;
use Aws\Api\Service;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\HandlerList;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\Signature\SignatureV4;
use Aws\Utils;
use GuzzleHttp\Psr7\Request;

/**
 * @covers Aws\Middleware
 */
class MiddlewareTest extends \PHPUnit_Framework_TestCase
{
    public function testCanTapIntoHandlerList()
    {
        $list = new HandlerList();
        $list->setHandler(new MockHandler([new Result()]));
        $list->append('sign', Middleware::tap(function () use (&$called) {
            $called = func_get_args();
        }));
        $handler = $list->resolve();
        $handler(new Command('foo'), new Request('GET', 'http://exmaple.com'));
        $this->assertCount(2, $called);
        $this->assertInstanceOf('Aws\CommandInterface', $called[0]);
        $this->assertInstanceOf('Psr\Http\Message\RequestInterface', $called[1]);
    }

    public function testWrapsWithRetryMiddleware()
    {
        $list = new HandlerList();
        $list->setHandler(new MockHandler([new Result()]));
        $list->append('sign', Middleware::retry(function () use (&$called) {
            $called = true;
        }));
        $handler = $list->resolve();
        $handler(new Command('foo'), new Request('GET', 'http://exmaple.com'));
        $this->assertTrue($called);
    }

    public function testAddsRetrySubscriber()
    {
        $list = new HandlerList();
        $mock = new MockHandler([
            new Result(['@metadata' => ['statusCode' => 500]]),
            new Result(['@metadata' => ['statusCode' => 200]]),
        ]);
        $this->assertCount(2, $mock);
        $list->setHandler($mock);
        $list->append('sign', Middleware::retry());
        $handler = $list->resolve();
        $handler(new Command('foo'), new Request('GET', 'http://exmaple.com'));
        $this->assertCount(0, $mock);
    }

    public function testAddsSigner()
    {
        $list = new HandlerList();
        $mock = function ($command, $request) use (&$req) {
            $req = $request;
            return \GuzzleHttp\Promise\promise_for(
                new Result(['@metadata' => ['statusCode' => 200]])
            );
        };
        $list->setHandler($mock);
        $creds = new Credentials('foo', 'bar');
        $signature = new SignatureV4('a', 'b');
        $list->append('sign', Middleware::signer($creds, Utils::constantly($signature)));
        $handler = $list->resolve();
        $handler(new Command('foo'), new Request('GET', 'http://exmaple.com'));
        $this->assertTrue($req->hasHeader('Authorization'));
    }

    public function testBuildsRequests()
    {
        $r = new Request('GET', 'http://www.foo.com');
        $serializer = function (CommandInterface $command) use ($r, &$called) {
            $called = true;
            return $r;
        };
        $list = new HandlerList();
        $list->setHandler(new MockHandler([new Result()]));
        $list->append('sign', Middleware::requestBuilder($serializer));
        $handler = $list->resolve();
        $handler(new Command('foo'));
        $this->assertTrue($called);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage [a] is missing and is a required parameter
     */
    public function testValidatesCommands()
    {
        $list = new HandlerList();
        $list->setHandler(new MockHandler([new Result()]));
        $api = new Service(
            function () {
                return [
                    'operations' => [
                        'foo' => [
                            'input' => ['shape'=> 'foo']
                        ]
                    ],
                    'shapes' => [
                        'foo' => [
                            'type' => 'structure',
                            'required' => ['a'],
                            'members' => [
                                'a' => ['shape' => 'a']
                            ]
                        ],
                        'a' => ['type' => 'string']
                    ]
                ];
            },
            'a',
            'b'
        );
        $list->append('validate', Middleware::validation($api));
        $handler = $list->resolve();

        try {
            $handler(new Command('foo', ['a' => 'b']), new Request('GET', 'http://foo.com'));
        } catch (\InvalidArgumentException $e) {
            $this->fail();
        }

        $handler(new Command('foo'));
    }

    public function testExtractsSourceFileIntoBody()
    {
        $list = new HandlerList();
        $list->setHandler(function ($command, $request) use (&$called) {
            $called = true;
            $this->assertNotNull($command['Body']);
            $this->assertNull($command['SourceFile']);
        });
        $provider = ApiProvider::defaultProvider();
        $service = new Service($provider, 's3', 'latest');
        $list->append('init', Middleware::sourceFile($service));
        $handler = $list->resolve();
        $handler(new Command('PutObject', [
            'Bucket'     => 'test',
            'Key'        => 'key',
            'SourceFile' => __FILE__
        ]), new Request('PUT', 'http://foo.com'));
        $this->assertTrue($called);
    }
}