<?php
namespace Mf2\HCard;

// http://microformats.org/wiki/representative-h-card-parsing
function representative($parsed, $url) {

  $cards = find_hcards($parsed);

  if(count($cards) == 0)
    return false;

  // If the page contains an h-card with uid and url properties both matching
  // the page URL, the first such h-card is the representative h-card
  foreach($cards as $card) {
    if(has_value($card, 'url', $url) && has_value($card, 'uid', $url)) {
      return $card;
    }
  }

  // If no representative h-card was found, if the page contains an h-card with
  // a url property value which also has a rel=me relation (i.e. matches a URL
  // in parse_results.rels.me), the first such h-card is the representative h-card
  foreach($cards as $card) {
    if(array_key_exists('url', $card['properties'])) {
      foreach($card['properties']['url'] as $u) {
        if(has_rel($parsed, 'me', $u)) {
          return $card;
        }
      }
    }
  }

  // If no representative h-card was found, if the page contains one single
  // h-card with a url property matching the page URL, that h-card is the
  // representative h-card
  if(count($cards) == 1) {
    $card = $cards[0];
    if(has_value($card, 'url', $url)) {
      return $card;
    }
  }

  return false;
}

// Return a flattened list of all h-cards on the page at any depth
function find_hcards($parsed) {
  $cards = [];

  if(is_microformat($parsed)) {
    if(in_array('h-card', $parsed['type'])) {
      $cards[] = $parsed;
    }
    foreach($parsed['properties'] as $propArray) {
      foreach($propArray as $prop) {
        if(is_microformat($prop)) {
          $cards = array_merge($cards, find_hcards($prop));
        }
      }
    }
    if(isset($parsed['children'])) {
      foreach($parsed['children'] as $item) {
        $cards = array_merge($cards, find_hcards($item));
      }
    }
  } else if(is_microformat_collection($parsed)) {
    foreach($parsed['items'] as $item) {
      $cards = array_merge($cards, find_hcards($item));
    }
  }

  return $cards;
}

####
## Utility functions

function has_numeric_keys(array $arr) {
	foreach($arr as $key=>$val)
    if(is_numeric($key))
      return true;
	return false;
}

function is_microformat($mf) {
	return is_array($mf) && !has_numeric_keys($mf) && !empty($mf['type']) && isset($mf['properties']);
}

function is_microformat_collection($mf) {
	return is_array($mf) && isset($mf['items']) && is_array($mf['items']);
}

// Searches all of $item's values of $property for the $value and returns true if found
function has_value($item, $property, $value) {
  if(!is_microformat($item)) return false;
  if(!array_key_exists($property, $item['properties'])) return false;
  foreach($item['properties'][$property] as $v) {
    if($v == $value) {
      return true;
    }
  }
  return false;
}

// Searches the given list of rel values for $value
function has_rel($parsed, $property, $value) {
  if(!array_key_exists('rels', $parsed)) return false;
  if(!array_key_exists($property, $parsed['rels'])) return false;
  foreach($parsed['rels'][$property] as $v) {
    if($v == $value) {
      return true;
    }
  }
  return false;
}

####

function k($a, $k, $d=null) {
  return array_key_exists($k, $a) ? $a[$k] : $d;
}

####

function build_url($parsed_url) {
  $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
  $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
  $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
  $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
  $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
  $pass     = ($user || $pass) ? "$pass@" : '';
  $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
  $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
  $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
  return "$scheme$user$pass$host$port$path$query$fragment";
}

// For the purposes of this library, if there is no path, the '/' path is automatically added.
// This means http://example.com and http://example.com/ are equivalent
function urls_match($a, $b) {
  $a = parse_url($a);
  $b = parse_url($b);
  if(!isset($a['path'])) $a['path'] = '/';
  if(!isset($b['path'])) $b['path'] = '/';
  $a = build_url($a);
  $b = build_url($b);
  return $a == $b;
}
