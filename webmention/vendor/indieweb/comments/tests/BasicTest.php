<?php
class BasicTest extends PHPUnit_Framework_TestCase {

  private function loadFile($fn) {
    return json_decode(file_get_contents(dirname(__FILE__).'/data/'.$fn), true);
  }

  private $_refURL = 'http://caseorganic.com/post/1';

  private function buildHEntry($input, $author=false, $replyTo=true) {
    $entry = array(
      'type' => array('h-entry'),
      'properties' => array(
        'author' => array(
          ($author ?: array(
            'type' => array('h-card'),
            'properties' => array(
              'name' => array('Aaron Parecki'),
              'url' => array('http://aaronparecki.com/'),
              'photo' => array('http://aaronparecki.com/images/aaronpk.png')
            )
          ))
        ), 
        'published' => array('2014-02-16T18:48:17-0800'),
        'url' => array('http://aaronparecki.com/post/1'),
      )
    );
    if($replyTo === true) {
      $entry['properties']['in-reply-to'] = array($this->_refURL);
    }
    if(is_array($replyTo)) {
      $entry['properties']['in-reply-to'] = array($replyTo);      
    }
    if(array_key_exists('content', $input)) {
      if(is_string($input['content'])) {
        $entry['properties']['content'] = array(array(
          'html' => $input['content'],
          'value' => strip_tags($input['content'])
        ));
      } else {
        $entry['properties']['content'] = array($input['content']);
      }
      unset($input['content']);
    }
    // The rest of the properties are all simple properties. Loop through and add them all as properties.
    foreach($input as $key=>$val) {
      $entry['properties'][$key] = array($val);
    }
    return $entry;
  }

  public function testBasicExample() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'summary' => 'post summary', 
      'content' => 'this is some content'
    )), $this->_refURL, 90);
    $this->assertEquals(array(
      'type' => 'reply',
      'author' => array(
        'name' => 'Aaron Parecki',
        'photo' => 'http://aaronparecki.com/images/aaronpk.png',
        'url' => 'http://aaronparecki.com/'
      ),
      'published' => '2014-02-16T18:48:17-0800',
      'name' => 'post name',
      'text' => 'this is some content',
      'url' => 'http://aaronparecki.com/post/1'
    ), $result);
  }

  public function testContentTooLongSummaryIsOk() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'summary' => 'post summary', 
      'content' => '<p>this is some content but it is longer than 90 characters so the summary will be used instead</p>'
    )), $this->_refURL, 90);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('post summary', $result['text']);
  }

  public function testContentTooLongSummaryTooLong() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'summary' => 'in this case the post summary is also too long, so a truncated version should be displayed instead', 
      'content' => '<p>this is some content but it is longer than 90 characters so the summary will be used instead</p>'
    )), $this->_refURL, 90);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('in this case the post summary is also too long, so a truncated version should be ...', $result['text']);
  }

  public function testContentTooLongNoSummary() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'content' => '<p>this is some content but it is longer than 90 characters so it will be truncated because there is no summary</p>'
    )), $this->_refURL, 90);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('this is some content but it is longer than 90 characters so it will be truncated ...', $result['text']);
  }

  public function testReplyNoContentNoSummaryNameOk() {
    // This one's tricky. If there is no content, the comments-presentation algorithm says to use the name.
    // So the parser won't return a value for the "name" property since the value would already
    // be used in the comment text, but only when it is a reply.
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name'
    )), $this->_refURL, 90);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('', $result['name']);
    $this->assertEquals('post name', $result['text']);
  }

  public function testMentionNoContentNoSummaryNameOk() {
    // This one's tricky. If there is no content, the comments-presentation algorithm says to use the name.
    // BUT, since this one is a mention, not a reply, it is returned in the "name" instead, with blank "text".
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name'
    ), false, false), $this->_refURL, 90);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('post name', $result['name']);
    $this->assertEquals('', $result['text']);
  }

  public function testNoContentNoSummaryNameTooLong() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'this is a really long post name'
    )), $this->_refURL, 20);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('this is a really ...', $result['text']);
  }

  public function testNameIsSubstringOfContent() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'The name of the note',
      'content' => 'The name of the note is a substring of the content'
    )), $this->_refURL, 200);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('', $result['name']);
    $this->assertEquals('The name of the note is a substring of the content', $result['text']);
  }

  public function testNameIsEllipsizedAndSubstringOfContent() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'The name of the note ...',
      'content' => 'The name of the note is a substring of the content'
    )), $this->_refURL, 200);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('', $result['name']);
    $this->assertEquals('The name of the note is a substring of the content', $result['text']);
  }

  public function testNamedArticleWithShortContent() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Post Name',
      'content' => 'The name of the post is different from the content'
    )), $this->_refURL, 200);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('Post Name', $result['name']);
    $this->assertEquals('The name of the post is different from the content', $result['text']);
  }

  public function testNamedArticleWithLongContent() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Post Name',
      'content' => 'The name of the post is different from the content, but in this case the content is too long and should be truncated.'
    )), $this->_refURL, 40);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('Post Name', $result['name']);
    $this->assertEquals('The name of the post is different ...', $result['text']);
  }

  public function testNameIsReturned() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Post Name',
      'content' => 'The name of the post is different from the content, but in this case the content is too long and should be truncated.'
    ), false, false), $this->_refURL, 40);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('Post Name', $result['name']);
    $this->assertEquals('The name of the post is different ...', $result['text']);
  }

  public function testNameIsTooLong() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'This is the name of the post but it is far too long. This sometimes happens when the name was generated from the implied parsing rules.'
    ), false, false), $this->_refURL, 40);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('This is the name of the post but it ...', $result['name']);
    $this->assertEquals('', $result['text']);
  }

  public function testNoMicroformatsIsMention() {
    $result = IndieWeb\comments\parse(array(), $this->_refURL, 200);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('', $result['text']);
  }

  public function testHCiteIsReply() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Post Name',
    ), false, array(
      'type' => array('h-cite'),
      'properties' => array(
        'url' => array($this->_refURL),
      )
    )), $this->_refURL, 40);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('Post Name', $result['text']);
  }

  /***************************************************************************
   * Multi-line comments
   */

  public function testMultiLineCommentFitsWithinLimits() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'content' => array(
        'value' => 'Line one
Line two
Line three'
      )
    ), false, false), $this->_refURL, 400, 3);
    $this->assertEquals('', $result['name']);
    $this->assertEquals('Line one
Line two
Line three', $result['text']);
  }

  public function testTrimMultiLineCommentHalfWayThroughThirdLine() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'content' => array(
        'html' => '#HouseOfCards s2e1 was good. 
But the best thing yesterday was getting to try a Boosted electric skateboard: <a class="auto-link" href="http://boostedboards.com/">http://boostedboards.com/</a>

Amazing. Handheld trigger remote control via Bluetooth. Forward and reverse. And I only tried it in &quot;turtle&quot; mode. In &quot;rabbit&quot; mode it can apparently do 20 miles an hour. Up hill. Jetsons-like motor sound included.

Forget about Segway, Boosted&#039;s electric skateboard feels like an object from the future dropped into the present, more in the realm of Marty&#039;s hoverboard (Back To The Future II &amp; III) and Y.T.&#039;s Smartwheels skateboard (Snow Crash).',
        'value' => "#HouseOfCards s2e1 was good. 
But the best thing yesterday was getting to try a Boosted electric skateboard: http://boostedboards.com/

Amazing. Handheld trigger remote control via Bluetooth. Forward and reverse. And I only tried it in \"turtle\" mode. In \"rabbit\" mode it can apparently do 20 miles an hour. Up hill. Jetsons-like motor sound included.

Forget about Segway, Boosted&#039;s electric skateboard feels like an object from the future dropped into the present, more in the realm of Marty's hoverboard (Back To The Future II &amp; III) and Y.T.'s Smartwheels skateboard (Snow Crash)."
      )
    ), false, false), $this->_refURL, 197, 3);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('', $result['name']);
    $this->assertEquals("#HouseOfCards s2e1 was good. 
But the best thing yesterday was getting to try a Boosted electric skateboard: http://boostedboards.com/

Amazing. Handheld trigger remote control via Bluetooth. ...", $result['text']);
  }

  public function testTrimShortTextMultiLineComment() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Post Name',
      'content' => array(
        'html' => '',
        'value' => "This comment spans multiple lines.\nOnly the first two lines should be returned.\nThe rest should be truncated."
      )
    ), false, false), $this->_refURL, 400, 2);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('Post Name', $result['name']);
    $this->assertEquals("This comment spans multiple lines.\nOnly the first two lines should be returned. ...", $result['text']);
  }

  public function testMultiLineCommentWithReallyLongName() {
    $result = IndieWeb\comments\parse($this->loadFile('post-tantek-1.json'), 'http://aaronparecki.com/events/2013/09/30/1/indieweb-dinner-at-21st-amendment', 400, 2);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('', $result['name']);
    $this->assertEquals('Well done @aaronpk! Real-time #indieweb comments:
http://aaronparecki.com/articles/2013/10/13/1/realtime-indieweb-comments ...', $result['text']);
  }

  public function testBnvk() {
    // bnvk linked to the https version of my post, but the site checks exclusively for http mentions
    $result = IndieWeb\comments\parse($this->loadFile('post-bnvk-1.json'), 'http://aaronparecki.com/notes/2013/10/12/2/indieweb', 400, 2);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('', $result['name']);
    $this->assertEquals("Hi ho, hi ho, it's a manual loading and sending of Webmention from my site!
\t\tReplied at
\t\tMar 30, 2014", $result['text']);
  }

  /***************************************************************************
   * Other post types
   */

  public function testReplyIsRSVP() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'RSVP Yes',
      'content' => 'Going to tonight\'s #IndieWeb Dinner @21stAmendment, 18:00. Hope to see you there!',
      'rsvp' => 'yes'
    )), $this->_refURL, 200);
    $this->assertEquals('rsvp', $result['type']);
    $this->assertEquals('Going to tonight\'s #IndieWeb Dinner @21stAmendment, 18:00. Hope to see you there!', $result['text']);
    $this->assertEquals('yes', $result['rsvp']);
  }

  public function testIsLike() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Liked this',
      'content' => 'liked this post',
      'like' => $this->_refURL
    )), $this->_refURL, 200);
    $this->assertEquals('like', $result['type']);
    $this->assertEquals('liked this post', $result['text']);
  }

  public function testIsLikeOf() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Liked this',
      'content' => 'liked this post',
      'like-of' => $this->_refURL
    )), $this->_refURL, 200);
    $this->assertEquals('like', $result['type']);
    $this->assertEquals('liked this post', $result['text']);
  }

  public function testIsRepostOf() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Reposted this',
      'content' => 'Reposted this',
      'repost-of' => $this->_refURL
    )), $this->_refURL, 200);
    $this->assertEquals('repost', $result['type']);
    $this->assertEquals('Reposted this', $result['text']);
  }

  public function testIsRepost() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Reposted this',
      'content' => 'Reposted this',
      'repost' => $this->_refURL
    )), $this->_refURL, 200);
    $this->assertEquals('repost', $result['type']);
    $this->assertEquals('Reposted this', $result['text']);
  }

  public function testIsNotInReplyTo() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Post Name',
      'content' => 'The name of the post is different from the content, but in this case the content is too long and should be truncated.'
    ), false, false), $this->_refURL, 40);
    $this->assertEquals('mention', $result['type']);
    $this->assertEquals('The name of the post is different ...', $result['text']);
  }

  public function testIsLikeOfHCite() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'Liked this',
      'content' => 'liked this post',
      'like-of' => array(
        'type' => 'h-cite',
        'properties' => array(
          'url' => array($this->_refURL)
        )
      )
    )), $this->_refURL, 200);
    $this->assertEquals('like', $result['type']);
    $this->assertEquals('liked this post', $result['text']);
  }

  /***************************************************************************
   * Author tests
   */

  public function testAuthorIsURL() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'summary' => 'post summary', 
      'content' => '<p>this is some content</p>'
    ), 'http://aaronparecki.com/'), $this->_refURL, 200);
    $author = $result['author'];
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals(false, $author['name']);
    $this->assertEquals(false, $author['photo']);
    $this->assertEquals('http://aaronparecki.com/', $author['url']);
  }

  public function testAuthorIsHCard() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'summary' => 'post summary', 
      'content' => '<p>this is some content</p>'
    )), $this->_refURL, 200);
    $author = $result['author'];
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('Aaron Parecki', $author['name']);
    $this->assertEquals('http://aaronparecki.com/images/aaronpk.png', $author['photo']);
    $this->assertEquals('http://aaronparecki.com/', $author['url']);
  }

  public function testAuthorIsHCardWithNoPhoto() {
    $result = IndieWeb\comments\parse($this->buildHEntry(array(
      'name' => 'post name', 
      'summary' => 'post summary', 
      'content' => '<p>this is some content</p>'
      ), array(
        'type' => array('h-card'),
        'properties' => array(
          'name' => array('Aaron Parecki'),
          'url' => array('http://aaronparecki.com')
        )
      )
    ), $this->_refURL, 200);
    $this->assertEquals('reply', $result['type']);
    $this->assertEquals('Aaron Parecki', $result['author']['name']);
    $this->assertEquals('', $result['author']['photo']);
    $this->assertEquals('http://aaronparecki.com', $result['author']['url']);
  }

  /**
   * @see https://github.com/indieweb/php-comments/issues/1
   * @see https://github.com/indieweb/php-comments/issues/3
   */
  public function testWorksWithNonEParsedContentProperty() {
    $result = IndieWeb\comments\parse([
      'type' => ['h-entry'],
      'properties' => [
        'content' => ['This is a scalar string content property as might have been parsed from p-content but very long This is a scalar string content property as might have been parsed from p-content']
      ]
    ]);

    $this->assertEquals('This is a scalar string content property as might have been parsed from p-content but very long This is a scalar string content property as might ...', $result['text']);
  }

  /**
   * @see https://github.com/indieweb/php-comments/issues/2
   */
  public function testHandlesHEntryWithEmptyNameCorrectly() {
    $result = Indieweb\comments\parse([
      'type' => ['h-entry'],
      'properties' => [
        'name' => [''],
        'content' => ['Blah blah blah']
      ]
    ]);

    $this->assertFalse($result['name']);
  }

}

