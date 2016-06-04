<?php
class URLTest extends PHPUnit_Framework_TestCase {

  public function testURLsMatch() {
    $url1 = 'http://example.com/?';
    $url2 = 'http://example.com/#';
    $match = Mf2\HCard\urls_match($url1, $url2);
    $this->assertTrue($match);
  }

  public function testURLsMatchSlash() {
    $url1 = 'http://example.com/';
    $url2 = 'http://example.com';
    $match = Mf2\HCard\urls_match($url1, $url2);
    $this->assertTrue($match);
  }

  public function testURLsDontMatch() {
    $url1 = 'http://example.com/';
    $url2 = 'http://example.com:80/';
    $match = Mf2\HCard\urls_match($url1, $url2);
    $this->assertFalse($match);
  }

  public function testBuildHTTPURL() {
    $url = [
      'scheme' => 'http',
      'host' => 'example.com',
      'port' => null,
      'user' => null,
      'pass' => null,
      'path' => '/foo',
      'query' => 'arg=val'
    ];
    $serialized = Mf2\HCard\build_url($url);
    $this->assertEquals('http://example.com/foo?arg=val', $serialized);
  }

  public function testSerializeFileURL() {
    $url = [
      'scheme' => 'file',
      'host' => null,
      'port' => null,
      'user' => null,
      'pass' => null,
      'path' => '/foo.xml',
      'query' => null
    ];
    $serialized = Mf2\HCard\build_url($url);
    $this->assertEquals('file:///foo.xml', $serialized);
  }

}
