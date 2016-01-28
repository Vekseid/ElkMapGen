<?php
/**
 * This is an Elkarte conversion of the code that generates the sitemaps for elliquiy.com.
 *
 * The SMF 2.0 source code is posted to the official Simple Machines Forum somewhere. Look through
 * my topics and find one with no replies. >_>
 *
 * We required a multi-file generator relatively early on, and it has served us well since.
 *
 * It is intended to be called via wget in cron, with the parameters
 * ?key=keyname&a=file
 *
 * where file is one of
 * boards
 * topics(hexadecimalnumber)
 * index
 *
 * e.g.
 * ?key=keyname&a=boards
 * ?key=keyname&a=topics0
 * ?key=keyname&a=topics1
 * ?key=keyname&a=topics2
 * ...
 * ?key=keyname&a=topicse
 * ?key=keyname&a=topicsf
 * ?key=keyname&a=index
 *
 * index should be called last, in order to get fresh dates on the others.
 *
 * Currently this does not support multiple board sitemaps, nor do I really expect it need such.
 * E has most of its boards private and BMR has most of its action hidden in PMs.
 *
 * If your forum breaks 20-30 million public posts, you might want to 
 */

// KEY is to make sure this script does not get called erroneously. Pick a key.
const KEY = 'yourkeynamehereitshouldbesomewhatlonglikeso';

// The idea is to actively prioritize boards that people might be searching for, and
// actively deprioritize stuff that is either extremely transient or of generally low
// value.

// The following defaults are for the first page of a non-sticky topic.

const PRIORITY_DEFAULT = 0.3;
const PRIORITY_MAX = 0.9;
const PRIORITY_MIN = 0.1;

// People searching are usually looking for posts in a topic, not the topics themselves.
const PRIORITY_BOARD = -0.1;

// Sticky topic more important. Also gets applied to popular topics.
const PRIORITY_STICKY = 0.1;

const PRIORITY_NOTFIRST = -0.2;

// Maximum priority boards. For Elliquiy, this is roleplay requests, blog threads, tutorials,
// rules & announcements, etc.
const BOARD_MAX = [2, 3, 32, 140, 361, 412, 453, 507, 563];

// Minimum priority (0.0) boards.
// 363 is Elliquiy's public forum games forum, while 376 is our Introduction forum - extremely transient
const BOARD_MIN = [363, 376];

// The base URL of your forum.
const URL_SITE = 'https://example.com/forums';

// The base URL of your sitemap directory.
const URL_MAP = 'https://example.com/sitemaps';

// Index filename.
const MAP_INDEX = 'sitemap.index.xml';

// Boardwalk.
const MAP_BOARDS = 'sitemap.boards.xml';

// Maps for your topics. The # character gets replaces with the appropriate hexadecimal digits.
const MAP_TOPICS = 'sitemap.topics#.xml';

// Extra maps to add to the index. Boards currently need to be here as well.
const MAP_ADDITIONAL = ['sitemap.main.xml', MAP_BOARDS];

// 0-2. If you need more than 2 digits, it is likely that you will need extra board sitemaps as well.
// Digits are in hexadecimal because in my experience,
const DIGITS_TOPICS = 1;

// By default, we expect to be installed where Elkarte's root index.php resides
const PATH_FORUM = __DIR__;

// Path to where the sitemap files will be stored.
define('PATH_MAP', realpath(PATH_FORUM . '/../sitemaps'));

if (!isset ($_GET['key']) || $_GET['key'] != KEY) die('Invalid key');

const MAP_XML_HEAD = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";


ini_set('display_errors', 0);

require_once ('SSI.php');

$db = database();


if (!isset ($_GET['a'])) {
  die('You need to set a=boards, a=index, or a=topics# where # is the hex sitemap digit of topics being created.');
}

function itemGen($url, $priority, $frequency = 'hourly') {
  return '   <url>'."\n".
         '      <loc>'.$url.'</loc>'."\n".
         '      <changefreq>'.$frequency.'</changefreq>'."\n".
         '      <priority>'.$priority.'</priority>'."\n".
         '   </url>'."\n";
}

if ($_GET['a'] === 'boards') {
  $result = $db->query('', 'SELECT value FROM {db_prefix}settings WHERE variable="defaultMaxTopics"');
  $row = mysqli_fetch_assoc($result);
  $topicsperpage = $row['value'];

  mysqli_free_result($result);

  $result = $db->query('', 'SELECT DISTINCT b.id_board, b.child_level, b.count_posts, b.member_groups, COUNT(t.id_topic) as numtopics FROM {db_prefix}boards as b INNER JOIN {db_prefix}topics as t ON t.id_board=b.id_board WHERE b.member_groups LIKE "%-1%" GROUP BY t.id_board');

  $fp = fopen(PATH_MAP.'/'.MAP_BOARDS, 'w');

  fwrite($fp, MAP_XML_HEAD);

  while ($row = mysqli_fetch_assoc($result)) {
    if (in_array($row['id_board'], BOARD_MAX)) {
      $boardvalue = (string) (PRIORITY_MAX + PRIORITY_BOARD);
    }
    elseif (in_array($row['id_board'], BOARD_MIN)) {
      $boardvalue = (string) (PRIORITY_MIN + PRIORITY_BOARD);
    }
    else {
      $boardvalue = (string) (PRIORITY_DEFAULT + PRIORITY_BOARD);
    }

    $string = itemGen(URL_SITE.'/index.php?board='.$row['id_board'].'.0', $boardvalue);

    fwrite($fp, $string);

    $numboardpages = (int) (floor($row['numtopics'] / $topicsperpage));

    for ($i = 0; $i < $numboardpages; $i++) {
      $start = $topicsperpage + $i * $topicsperpage;

      $string = itemGen(URL_SITE.'/index.php?board='.$row['id_board'].'.'.$start, $boardvalue, 'daily');

      fwrite($fp, $string);
    }
  }

  fwrite($fp, '</urlset>');
  fclose($fp);

  mysqli_free_result($result);
}
elseif ($_GET['a'] === 'index')
{
  $fp = fopen(PATH_MAP.'/'.MAP_INDEX, 'w');

  fwrite($fp, MAP_XML_HEAD);

  $topicmapper = explode('#', MAP_TOPICS);

  $sitemaps = MAP_ADDITIONAL;

  if (DIGITS_TOPICS) {
    $topicmaps = pow(16, DIGITS_TOPICS);

    for ($i = 0; $i < $topicmaps; $i++) {
      $sitemaps[] = $topicmapper[0].dechex($i).$topicmapper[1];
    }
  }
  else {
    $sitemaps[] = $topicmapper[0].$topicmapper[1];
  }

  foreach ($sitemaps as $map) {
    if (file_exists(PATH_MAP.'/'.$map)) {
      $mtime = filemtime(PATH_MAP.'/'.$map);
      $date = date('Y-m-d', $mtime);

      $string = '   <sitemap>'."\n".
                '      <loc>'.URL_MAP.'/'.$map.'</loc>'."\n".
                '      <lastmod>'.$date.'</lastmod>'."\n".
                '   </sitemap>'."\n";

      fwrite ($fp, $string);
    }
  }

  fwrite ($fp, '</sitemapindex>');
  fclose ($fp);
}
else
{
  $map = explode ('s', $_GET['a']);

  if ($map[0] != 'topic') {
    die('a needs to be topics#, where # is the hex id of the topic index.');
  }

  $result = $db->query('', 'SELECT value FROM {db_prefix}settings WHERE variable="defaultMaxMessages"');
  $row = mysqli_fetch_assoc($result);
  $postsperpage = $row['value'];

  mysqli_free_result($result);

  $modquery = '';
  $num = '';

  if (DIGITS_TOPICS > 0) {
    if (!isset ($map[1]) || !strlen($map[1])) {
      die('$sitemap_digits is over zero. a needs to be topics#, where # is the id of the topic index.');
    }

    $num = abs(intval(hexdec($map[1])));

    if ($num < pow(16, DIGITS_TOPICS)) {
      $modulo = pow(16, DIGITS_TOPICS);
      $modquery = ' && t.id_topic % '.$modulo.' = '.$num;
    }
    else {
      die('# in topics# needs to be a hexadecimal number with '.DIGITS_TOPICS.' digits.');
    }
  }

  $result = $db->query('', 'select t.id_topic, t.is_sticky, t.num_replies, b.id_board, m2.modified_time as fmodified_time, m1.poster_time as lposter_time, m1.modified_time as lmodified_time from {db_prefix}topics as t inner join {db_prefix}boards as b on b.id_board = t.id_board inner join {db_prefix}messages as m1 on t.id_last_msg=m1.id_msg inner join {db_prefix}messages as m2 on t.id_first_msg=m2.id_msg where b.member_groups LIKE "%-1%"');
  $topicmapper = explode('#', MAP_TOPICS);
  $fp = fopen(PATH_MAP.'/'.$topicmapper[0].dechex($num).$topicmapper[1], 'w');
  fwrite($fp, MAP_XML_HEAD);

  while ($row = mysqli_fetch_assoc($result)) {
    if (in_array($row['id_board'], BOARD_MAX)) {
      $topicvalue = PRIORITY_MAX;
    }
    elseif (in_array($row['id_board'], BOARD_MIN)) {
      $topicvalue = PRIORITY_MIN;
    }
    else {
      $topicvalue = PRIORITY_DEFAULT;
    }

    $date = date('Y-m-d', max($row['fmodified_time'], $row['lposter_time'], $row['lmodified_time']));

    if ($row['is_sticky'] || $row['num_replies'] >= $postsperpage) {
      $topicvalue += PRIORITY_STICKY;
    }

    $nextvalue = $topicvalue + PRIORITY_NOTFIRST;

    $string = '   <url>'."\n".
              '      <loc>'.URL_SITE.'/index.php?topic='.$row['id_topic'].'.0</loc>'."\n".
              '      <lastmod>'.$date.'</lastmod>'."\n".
              '      <changefreq>daily</changefreq>'."\n".
              '      <priority>'.$topicvalue.'</priority>'."\n".
              '   </url>'."\n";

    fwrite ($fp, $string);

    $numtopicpages = (int) (floor(($row['num_replies'] + 1) / $postsperpage));

    for ($i = 0; $i < $numtopicpages; $i++) {
      $start = $postsperpage + $i * $postsperpage;

      $string = '   <url>'."\n".
                '      <loc>'.URL_SITE.'/index.php?topic='.$row['id_topic'].'.'.$start.'</loc>'."\n".
                '      <lastmod>'.$date.'</lastmod>'."\n".
                '      <changefreq>daily</changefreq>'."\n".
                '      <priority>'.$nextvalue.'</priority>'."\n".
                '   </url>'."\n";

      fwrite($fp, $string);
    }
  }

  fwrite($fp, '</urlset>');
  fclose($fp);

  mysqli_free_result($result);
}
