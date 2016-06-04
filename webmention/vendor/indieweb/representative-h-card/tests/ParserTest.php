<?php
class ParserTest extends PHPUnit_Framework_TestCase {

  public function testFindHCardOnProperty() {
    $html = '<a href="http://example.com" class="h-card">Example</a>
      <div class="h-entry"><a href="http://example.com" class="p-author h-card">Example</a></div>';
    $parsed = Mf2\parse($html);
    $cards = Mf2\HCard\find_hcards($parsed);
    $this->assertEquals(2, count($cards));
  }

  public function testFindChildHCard() {
    $html = '<a href="http://example.com" class="h-card">Example</a>
      <div class="h-entry"><a href="http://example.com" class="h-card">Example</a></div>';
    $parsed = Mf2\parse($html);
    $cards = Mf2\HCard\find_hcards($parsed);
    $this->assertEquals(2, count($cards));
  }

  public function testFindDeeplyNestedHCard() {
    $html = '<a href="http://example.com" class="h-card">Example</a>
      <div class="h-feed">
        <div class="h-entry"><a href="http://example.com" class="p-author h-card">Example</a></div>
      </div>';
    $parsed = Mf2\parse($html);
    $cards = Mf2\HCard\find_hcards($parsed);
    $this->assertEquals(2, count($cards));
  }

  public function testHasValue() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url"></a></div>';
    $parsed = Mf2\parse($html);
    $item = $parsed['items'][0];
    $this->assertTrue(Mf2\HCard\has_value($item, 'url', 'http://example.com/'));
  }

  public function testDoesntHaveValue() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url"></a></div>';
    $parsed = Mf2\parse($html);
    $item = $parsed['items'][0];
    $this->assertFalse(Mf2\HCard\has_value($item, 'uid', 'http://example.com/'));
    $this->assertFalse(Mf2\HCard\has_value($item, 'url', 'http://example.net/'));
  }

  public function testHasRel() {
    $html = '<div class="h-card"><a href="http://example.com/" class="u-url" rel="me"></a></div>';
    $parsed = Mf2\parse($html);
    $this->assertTrue(Mf2\HCard\has_rel($parsed, 'me', 'http://example.com/'));
  }

}
