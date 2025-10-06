<?php
namespace Arshline\Tests\Unit;

use Arshline\Hosha2\Hosha2OpenAIHttpClient;
use PHPUnit\Framework\TestCase;

// Provide a minimal WP_Error stub if WordPress core not loaded.
if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $msg;
        public function __construct($code, $message = '') { $this->msg = $message ?: (string)$code; }
        public function get_error_message(){ return $this->msg; }
    }
}

/**
 * NOTE: We simulate internal protected method behavior through reflection because
 * wp_remote_post isn't easily mockable without WP test suite. This focuses on mapping logic.
 */
class Hosha2HttpClientErrorMappingTest extends TestCase
{
    protected function getClient(): Hosha2OpenAIHttpClient
    {
        return new Hosha2OpenAIHttpClient('dummy');
    }

    protected function invoke($object, string $method, array $args = [])
    {
        $ref = new \ReflectionClass($object);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }

    public function testHttp401MapsToInvalidApiKey()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [401, ['error'=>['message'=>'No auth', 'type'=>'authentication_error']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['INVALID_API_KEY'], $res['code']);
    }

    public function testHttp429MapsRateLimit()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [429, ['error'=>['message'=>'Too many','type'=>'rate_limit_error']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['RATE_LIMIT'], $res['code']);
    }

    public function testHttp503Overloaded()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [503, ['error'=>['message'=>'model overloaded','type'=>'server_error']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['MODEL_OVERLOADED'], $res['code']);
    }

    public function testParseOpenAIErrorRefinesMapping()
    {
        $client = $this->getClient();
        $current = ['code'=>Hosha2OpenAIHttpClient::ERROR_CODES['UNKNOWN'], 'message'=>'generic'];
        $refined = $this->invoke($client, 'parseOpenAIError', [['error'=>['type'=>'invalid_request_error','message'=>'bad json']], $current, 400]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['INVALID_REQUEST'], $refined['code']);
    }

    public function testParseOpenAIErrorKeepsOriginalWhenUnknownType()
    {
        $client = $this->getClient();
        $current = ['code'=>Hosha2OpenAIHttpClient::ERROR_CODES['SERVER_ERROR'], 'message'=>'srv'];
        $refined = $this->invoke($client, 'parseOpenAIError', [['error'=>['type'=>'strange_error','message'=>'???']], $current, 500]);
        $this->assertSame($current['code'], $refined['code']);
    }

    public function testHttp500MapsServerError()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [500, ['error'=>['message'=>'boom','type'=>'server_error']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['SERVER_ERROR'], $res['code']);
    }

    public function testHttp503GenericServerError()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [503, ['error'=>['message'=>'maintenance','type'=>'server_error']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['SERVER_ERROR'], $res['code']);
    }

    public function testHttp418Unknown()
    {
        $client = $this->getClient();
    $res = $this->invoke($client, 'mapHttpError', [418, ['error'=>['message'=>'teapot']]]);
    $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['INVALID_REQUEST'], $res['code']);
    }

    public function testInvalidApiKey403()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [403, ['error'=>['message'=>'forbidden']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['INVALID_API_KEY'], $res['code']);
    }

    public function testInvalidRequest400()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [400, ['error'=>['message'=>'bad request']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['INVALID_REQUEST'], $res['code']);
    }

    public function testRateLimit429RefinedByParse()
    {
        $client = $this->getClient();
        $mapped = $this->invoke($client, 'mapHttpError', [429, ['error'=>['message'=>'Too many','type'=>'rate_limit_error']]]);
        $refined = $this->invoke($client, 'parseOpenAIError', [['error'=>['type'=>'rate_limit_error','message'=>'Too many']], $mapped, 429]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['RATE_LIMIT'], $refined['code']);
    }

    public function testModelOverloaded503ExplicitKeyword()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [503, ['error'=>['message'=>'model overloaded please retry','type'=>'server_error']]]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['MODEL_OVERLOADED'], $res['code']);
    }

    public function testMalformedJsonStillMaps()
    {
        $client = $this->getClient();
        $res = $this->invoke($client, 'mapHttpError', [400, null]);
        $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['INVALID_REQUEST'], $res['code']);
    }

    public function testMapWpErrorTimeout()
    {
        $client = $this->getClient();
    $res = $this->invoke($client, 'mapWpError', [new \WP_Error('timeout','request timeout after 30000 ms')]);
    $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['TIMEOUT'], $res['code']);
    }

    public function testMapWpErrorNetworkGeneric()
    {
        $client = $this->getClient();
    $res = $this->invoke($client, 'mapWpError', [new \WP_Error('network','cURL connection refused')]);
    $this->assertEquals(Hosha2OpenAIHttpClient::ERROR_CODES['NETWORK_ERROR'], $res['code']);
    }
}
?>