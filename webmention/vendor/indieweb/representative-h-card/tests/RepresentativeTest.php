<?php
class RepresentativeTest extends PHPUnit_Framework_TestCase {

  public function testNoHCard() {
    $html = '<a href="http://example.com/">Example</a>';
    $parsed = Mf2\parse($html);
    $representative = Mf2\HCard\representative($parsed, 'http://example.com/');
    $this->assertFalse($representative);
  }

  public function testTopLevelHCardWithURLUID() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url u-uid">Example</a></div>';
    $parsed = Mf2\parse($html);
    $representative = Mf2\HCard\representative($parsed, 'http://example.com/');
    $this->assertInternalType('array', $representative);
    $this->assertContains('http://example.com/', $representative['properties']['url']);
  }

  public function testTopLevelHCardURLWithRelMe() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url" rel="me">Example</a></div>';
    $parsed = Mf2\parse($html);
    $representative = Mf2\HCard\representative($parsed, 'http://alternate.example.net/'); // different page URL than the h-card URL
    $this->assertInternalType('array', $representative);
    $this->assertContains('http://example.com/', $representative['properties']['url']);
  }

  public function testExactlyOneHCards() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url">Example</a></div>';
    $parsed = Mf2\parse($html);
    $representative = Mf2\HCard\representative($parsed, 'http://example.com/');
    $this->assertContains('http://example.com/', $representative['properties']['url']);
  }

  public function testMultipleHCardsURLNoRelMe() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url">Example</a></div>
      <div class="h-card"><a href="http://example.com/" class="u-url">Example</a></div>';
    $parsed = Mf2\parse($html);
    $representative = Mf2\HCard\representative($parsed, 'http://example.com/');
    $this->assertFalse($representative);
  }

}
