<?php
/*
  These tests query peoples' live websites, so may fail in the future if
  people change their markup.

  This file is not run by default using phpunit, but you can run it manually:
  $ phpunit.phar tests/LiveWebsites.php
*/
class LiveWebsites extends PHPUnit_Framework_TestCase {

  private function parse($url) {
    return Mf2\fetch($url);
  }

  public function testAaronpk() {
    $url = 'http://aaronparecki.com/';
    $parsed = $this->parse($url);
    $representative = Mf2\HCard\representative($parsed, $url);
    $this->assertContains('http://aaronparecki.com/', $representative['properties']['url']);
  }

  public function testTantek() {
    $url = 'http://tantek.com/';
    $parsed = $this->parse($url);
    $representative = Mf2\HCard\representative($parsed, $url);
    $this->assertContains('http://tantek.com/', $representative['properties']['url']);
  }

  public function testGregor() {
    $url = 'http://gregorlove.com/';
    $parsed = $this->parse($url);
    $representative = Mf2\HCard\representative($parsed, $url);
    $this->assertContains('http://gregorlove.com/', $representative['properties']['url']);
  }

}
