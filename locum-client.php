<?php
/**
 * Locum is a software library that abstracts ILS functionality into a
 * catalog discovery layer for use with such things as bolt-on OPACs like
 * SOPAC.
 * @package Locum
 * @author John Blyberg
 */

require_once('locum.php');

/**
 * The Locum Client class represents the "front end" of Locum.  IE, the interactive piece.
 * This is the class you would use to do searches, place holds, get patron info, etc.
 * Ideally, this code should never have to be touched.
 */
class locum_client extends locum {

  /**
   * Does an index search via Sphinx and returns the results
   *
   * @param string $type Search type.  Valid types are: author, title, series, subject, keyword (default)
   * @param string $term Search term/phrase
   * @param int $limit Number of results to return
   * @param int $offset Where to begin result set -- for pagination purposes
   * @param array $sort_array Numerically keyed array of sort parameters.  Valid options are: newest, oldest
   * @param array $location_array Numerically keyed array of location params.  NOT IMPLEMENTED YET
   * @param array $facet_args String-keyed array of facet parameters. See code below for array structure
   * @return array String-keyed result set
   */
  public function search($type, $term, $limit, $offset, $sort_opt = NULL, $format_array = array(), $location_array = array(), $facet_args = array(), $override_search_filter = FALSE, $limit_available = FALSE, $show_inactive = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($type, $term, $limit, $offset, $sort_opt, $format_array, $location_array, $facet_args, $override_search_filter, $limit_available);
    }

    require_once($this->locum_config['sphinx_config']['api_path'] . '/sphinxapi.php');
    $db =& MDB2::connect($this->dsn);

    $term_arr = explode('?', trim(preg_replace('/\//', ' ', $term)));
    $term = trim($term_arr[0]);

    if ($term == '*' || $term == '**') {
      $term = '';
    } else {
      $term_prestrip = $term;
      //$term = preg_replace('/[^A-Za-z0-9*\- ]/iD', '', $term);
      $term = preg_replace('/\*\*/','*', $term);
    }
    $final_result_set['term'] = $term;
    $final_result_set['type'] = trim($type);

    $cl = new SphinxClient();

    $cl->SetServer($this->locum_config['sphinx_config']['server_addr'], (int) $this->locum_config['sphinx_config']['server_port']);

    // Defaults to 'keyword', non-boolean
    $bool = FALSE;
    $cl->SetMatchMode(SPH_MATCH_ALL);

    if(!$term) {
      // Searches for everything (usually for browsing purposes--Hot/New Items, etc..)
      $cl->SetMatchMode(SPH_MATCH_ANY);
    } else {

      // Is it a boolean search?
      if(preg_match("/ \| /i", $term) || preg_match("/ \-/i", $term) || preg_match("/ \!/i", $term)) {
        $cl->SetMatchMode(SPH_MATCH_BOOLEAN);
        $bool = TRUE;
      }
      if(preg_match("/ OR /i", $term)) {
        $cl->SetMatchMode(SPH_MATCH_BOOLEAN);
        $term = preg_replace('/ OR /i',' | ',$term);
        $bool = TRUE;
      }

      // Is it a phrase search?
      if(preg_match("/\"/i", $term) || preg_match("/\@/i", $term)) {
        $cl->SetMatchMode(SPH_MATCH_EXTENDED2);
        $bool = TRUE;
      }
    }

    // Set up for the various search types
    switch ($type) {
      case 'author':
        $cl->SetFieldWeights(array('author' => 50, 'addl_author' => 30));
        $idx = 'bib_items_author';
        break;
      case 'title':
        $cl->SetFieldWeights(array('title' => 50, 'title_medium' => 50, 'series' => 30));
        $idx = 'bib_items_title';
        break;
      case 'series':
        $cl->SetFieldWeights(array('title' => 5, 'series' => 80));
        $idx = 'bib_items_title';
        break;
      case 'subject':
        $idx = 'bib_items_subject';
        break;
      case 'callnum':
        $cl->SetFieldWeights(array('callnum' => 100));
        $idx = 'bib_items_callnum';
        //$cl->SetMatchMode(SPH_MATCH_ANY);
        break;
      case 'tags':
        $cl->SetFieldWeights(array('tag_idx' => 100));
        $idx = 'bib_items_tags';
        //$cl->SetMatchMode(SPH_MATCH_PHRASE);
        break;
      case 'reviews':
        $cl->SetFieldWeights(array('review_idx' => 100));
        $idx = 'bib_items_reviews';
        break;
      case 'keyword':
      default:
        $cl->SetFieldWeights(array('title' => 50, 'title_medium' => 50, 'author' => 70, 'addl_author' => 40, 'tag_idx' =>35, 'series' => 25, 'review_idx' => 10, 'notes' => 10, 'subjects' => 5 ));
        $idx = 'bib_items_keyword';
        break;

    }

    // Filter out the records we don't want shown, per locum.ini
    if (!$override_search_filter) {
      if (trim($this->locum_config['location_limits']['no_search'])) {
        $cfg_filter_arr = $this->csv_parser($this->locum_config['location_limits']['no_search']);
        foreach ($cfg_filter_arr as $cfg_filter) {
          $cfg_filter_vals[] = $this->string_poly($cfg_filter);
        }
        $cl->SetFilter('loc_code', $cfg_filter_vals, TRUE);
      }
    }

    // Valid sort types are 'newest' and 'oldest'.  Default is relevance.
    switch($sort_opt) {
      case 'newest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'pub_year DESC, @relevance DESC');
        break;
      case 'oldest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'pub_year ASC, @relevance DESC');
        break;
      case 'catalog_newest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'bib_created DESC, @relevance DESC');
        break;
      case 'catalog_oldest':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'bib_created ASC, @relevance DESC');
        break;
      case 'title':
        $cl->SetSortMode(SPH_SORT_ATTR_ASC, 'title_ord');
        break;
      case 'author':
        $cl->SetSortMode(SPH_SORT_EXTENDED, 'author_null ASC, author_ord ASC');
        break;
      case 'top_rated':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'rating_idx');
        break;
      case 'popular_week':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_week');
        break;
      case 'popular_month':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_month');
        break;
      case 'popular_year':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_year');
        break;
      case 'popular_total':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'hold_count_total');
        break;
      case 'atoz':
        $cl->SetSortMode(SPH_SORT_ATTR_ASC, 'title_ord');
        break;
      case 'ztoa':
        $cl->SetSortMode(SPH_SORT_ATTR_DESC, 'title_ord');
        break;
      default:
#        if ($type == 'title') {
#          // We get better results in title matches if we also rank by title length
#          $cl->SetSortMode(SPH_SORT_EXTENDED, 'titlelength ASC, @relevance DESC');
#        } else {
          //$cl->SetSortMode(SPH_SORT_EXTENDED, '@relevance DESC');
	  $cl->SetSortMode(SPH_SORT_EXPR, "@weight + (hold_count_total)*0.1");
#        }
        break;
    }

    // Filter by material types
    if (is_array($format_array)) {
      foreach ($format_array as $format) {
        if (strtolower($format) != 'all') {
          $filter_arr_mat[] = $this->string_poly(trim($format));
        }
      }
      if (count($filter_arr_mat)) { $cl->SetFilter('mat_code', $filter_arr_mat); }
    }

    // Filter by location
    if (count($location_array)) {
      foreach ($location_array as $location) {
        if (strtolower($location) != 'all') {
          $filter_arr_loc[] = $this->string_poly(trim($location));
        }
      }
      if (count($filter_arr_loc)) { $cl->SetFilter('loc_code', $filter_arr_loc); }
    }

    // Filter inactive records
    if (!$show_inactive) {
      $cl->SetFilter('active', array('0'), TRUE);
    }

    $cl->SetRankingMode(SPH_RANK_WORDCOUNT);
    $cl->SetLimits(0, 5000, 5000);
    $sph_res_all = $cl->Query($term, $idx); // Grab all the data for the facetizer

    // If original match didn't return any results, try a proximity search
    if(empty($sph_res_all['matches']) && $bool == FALSE && $term != "*" && $type != "tags") {
      $term = '"' . $term . '"/1';
      $cl->SetMatchMode(SPH_MATCH_EXTENDED2);
      $sph_res_all = $cl->Query($term, $idx);
      $forcedchange = 'yes';
    }

    // Paging/browsing through the result set.
    $cl->SetLimits((int) $offset, (int) $limit);

    // And finally.... we search.
    $sph_res = $cl->Query($term, $idx);

    // Include descriptors
    $final_result_set['num_hits'] = $sph_res['total'];
    if ($sph_res['total'] <= $this->locum_config['api_config']['suggestion_threshold'] || $forcedchange == 'yes') {
      if ($this->locum_config['api_config']['use_yahoo_suggest'] == TRUE) {
        $final_result_set['suggestion'] = $this->yahoo_suggest($term_prestrip);
      }
    }

    if (is_array($sph_res['matches'])) {
      foreach ($sph_res['matches'] as $bnum => $attr) {
        $bib_hits[] = (string)$bnum;
      }
    }
    if (is_array($sph_res_all['matches'])) {
      foreach ($sph_res_all['matches'] as $bnum => $attr) {
        $bib_hits_all[] = (string)$bnum;
      }
    }

    // Limit list to available
    if ($limit_available && $final_result_set['num_hits'] && (array_key_exists($limit_available, $this->locum_config['branches']) || $limit_available == 'any')) {

      $limit_available = trim(strval($limit_available));

      // Remove bibs that we know are not available
      $cache_cutoff = date("Y-m-d H:i:00", time() - (60 * $this->locum_config['avail_cache']['cache_cutoff']));

      // Remove bibs that are not in this location
      $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
      $utfprep = $db->query($utf);

      $sql = "SELECT bnum, branch, count_avail FROM locum_avail_branches WHERE bnum IN (" . implode(", ", $bib_hits_all) . ") AND timestamp > '$cache_cutoff'";
      $init_result =& $db->query($sql);
      if ($init_result) {
        $branch_info_cache = $init_result->fetchAll(MDB2_FETCHMODE_ASSOC);
        $bad_bibs = array();
        $good_bibs = array();
        foreach ($branch_info_cache as $item_binfo) {
          if (($item_binfo['branch'] == $limit_available || $limit_available == 'any') && $item_binfo['count_avail'] > 0) {
            if (!in_array($item_binfo['bnum'], $good_bibs)) {
              $good_bibs[] = (string)$item_binfo['bnum'];
            }
          } else {
            $bad_bibs[] = (string)$item_binfo['bnum'];
          }
        }
      }
      $unavail_bibs = array_values(array_diff($bad_bibs, $good_bibs));
      $bib_hits_all = array_values(array_diff($bib_hits_all, $unavail_bibs));

      // rebuild from the full list
      unset($bib_hits);
      $available_count = 0;
      foreach ($bib_hits_all as $key => $bib_hit) {
        $bib_avail = $this->get_item_status($bib_hit);
        if ($limit_available == 'any') {
          $available = $bib_avail['avail'];
        } else {
          $available = $bib_avail['branches'][$limit_available]['avail'];
        }
        if ($available) {
          $available_count++;
          if ($available_count > $offset) {
            $bib_hits[] = $bib_hit;
            if (count($bib_hits) == $limit) {
              //found as many as we need for this page
              break;
            }
          }
        } else {
          // remove the bib from the bib_hits_all array
          unset($bib_hits_all[$key]);
        }
      }

      // trim out the rest of the array based on *any* cache value
      if(!empty($bib_hits_all)) {
        $sql = "SELECT bnum FROM locum_avail_branches WHERE bnum IN (" . implode(",", $bib_hits_all) . ") AND count_avail > 0";
        $init_result =& $db->query($sql);
        if ($init_result) {
          $avail_bib_arr = $init_result->fetchCol();
          foreach ($bib_hits_all as $bnum_avail_chk) {
            if (in_array($bnum_avail_chk, $avail_bib_arr)) {
              $new_bib_hits_all[] = $bnum_avail_chk;
            }
          }
        }
        $bib_hits_all = $new_bib_hits_all;
        unset($new_bib_hits_all);
      }
    }

    // Refine by facets

    if (count($facet_args)) {
      $where = '';

      // Series
      if ($facet_args['facet_series']) {
        $where .= ' AND (';
        $or = '';
        foreach ($facet_args['facet_series'] as $series) {
          $where .= $or . ' series LIKE \'' . $db->escape($series, 'text') . '%\'';
          $or = ' OR';
        }
        $where .= ')';
      }

      // Language
      if ($facet_args['facet_lang']) {
        foreach ($facet_args['facet_lang'] as $lang) {
          $lang_arr[] = $db->quote($lang, 'text');
        }
        $where .= ' AND lang IN (' . implode(', ', $lang_arr) . ')';
      }

      // Pub. Year
      if ($facet_args['facet_year']) {
        $where .= ' AND pub_year IN (' . implode(', ', $facet_args['facet_year']) . ')';
      }

      // Pub. Decade
      if ($facet_args['facet_decade']) {
        $where .= ' AND pub_decade IN (' . implode(', ', $facet_args['facet_decade']) . ')';
      }

      // Ages
      if (count($facet_args['age'])) {
        $age_or = '';
        $age_sql_cond = '';
        foreach ($facet_args['age'] as $facet_age) {
          $age_sql_cond .= $age_or . "age = '$facet_age'";
          $age_or = ' OR ';
        }
        $sql = 'SELECT DISTINCT(bnum) FROM locum_avail_ages WHERE bnum IN (' . implode(', ', $bib_hits_all) . ") AND ($age_sql_cond)";
        $init_result =& $db->query($sql);
        $age_hits = $init_result->fetchCol();
        $bib_hits_all = array_intersect($bib_hits_all, $age_hits);
      }

      if(!empty($bib_hits_all)) {
        $sql1 = 'SELECT bnum FROM locum_facet_heap WHERE bnum IN (' . implode(', ', $bib_hits_all) . ')' . $where;
        $sql2 = 'SELECT CAST(bnum as CHAR(12)) FROM locum_facet_heap WHERE bnum IN (' . implode(', ', $bib_hits_all) . ')' . $where . ' ORDER BY FIELD(bnum,' . implode(', ', $bib_hits_all) . ") LIMIT $offset, $limit";
        $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
        $utfprep = $db->query($utf);
        $init_result =& $db->query($sql1);
        $bib_hits_all = $init_result->fetchCol();
        $init_result =& $db->query($sql2);
        $bib_hits = $init_result->fetchCol();
      }

    }

    // Get the totals
    $facet_total = count($bib_hits_all);
    $final_result_set['num_hits'] = $facet_total;

    // First, we have to get the values back, unsorted against the Sphinx-sorted array
    if (count($bib_hits)) {
      $skip_avail = $this->csv_parser($this->locum_config['format_special']['skip_avail']);
      $init_bib_arr = $this->get_bib_items_arr($bib_hits);
      //$utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
      //$utfprep = $db->query($utf);
      //$init_result =& $db->query($sql);
      //$init_bib_arr = $init_result->fetchAll(MDB2_FETCHMODE_ASSOC);
      foreach ($init_bib_arr as $init_bib) {
        if($init_bib['doc']['bnum']){
          // Get availability
          if (in_array($init_bib['doc']['mat_code'], $skip_avail)) {
            $init_bib['doc']['status'] = $this->get_item_status($init_bib['doc']['bnum'], FALSE, TRUE);
          }
          else {
            $init_bib['doc']['status'] = $this->get_item_status($init_bib['doc']['bnum']);
          }
        }
        // Clean up the Stdnum
        //$init_bib['doc']['stdnum'] = preg_replace('/[^\d]/','', $init_bib['doc']['stdnum']);
        if($init_bib['doc']['sphinxid']){
          $bib_reference_arr[(string) $init_bib['doc']['sphinxid']] = $init_bib['doc'];
        }
        else {
          $bib_reference_arr[(string) $init_bib['doc']['_id']] = $init_bib['doc'];
        }
      }

      // Now we reconcile against the sphinx result
      foreach ($sph_res_all['matches'] as $sph_bnum => $sph_binfo) {
        if (in_array($sph_bnum, $bib_hits)) {
          $final_result_set['results'][] = $bib_reference_arr[$sph_bnum];
        }
      }
    }

    $db->disconnect();
    $final_result_set['facets'] = $this->facetizer($bib_hits_all);
    if($forcedchange == 'yes') { $final_result_set['changed'] = 'yes'; }

    return $final_result_set;

  }

  /**
   * Formulates the array used to put together the faceted search panel.
   * This function is called from the search function.
   *
   * @param array $bib_hits_all Standard array of bib numbers
   * @return array Faceted array of information for bib numbers passed.  Keyed by: mat, series, loc, lang, pub_year
   */
  public function facetizer($bib_hits_all) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bib_hits_all);
    }

    $db =& MDB2::connect($this->dsn);
    if (count($bib_hits_all)) {
      $where_str = 'WHERE bnum IN (' . implode(",", $bib_hits_all) . ')';

      $sql['mat'] = 'SELECT DISTINCT mat_code, COUNT(mat_code) AS mat_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY mat_code ORDER BY mat_code_sum DESC';
      $sql['series'] = 'SELECT DISTINCT series, COUNT(series) AS series_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY series ORDER BY series ASC';
      $sql['loc'] = 'SELECT DISTINCT loc_code, COUNT(loc_code) AS loc_code_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY loc_code ORDER BY loc_code_sum DESC';
      $sql['lang'] = 'SELECT DISTINCT lang, COUNT(lang) AS lang_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY lang ORDER BY lang_sum DESC';
      $sql['pub_year'] = 'SELECT DISTINCT pub_year, COUNT(pub_year) AS pub_year_sum FROM locum_facet_heap ' . $where_str . 'GROUP BY pub_year ORDER BY pub_year DESC';
      $sql['pub_decade'] = 'SELECT DISTINCT pub_decade, COUNT(pub_decade) AS pub_decade_sum FROM locum_facet_heap ' . $where_str . ' GROUP BY pub_decade ORDER BY pub_decade DESC';

      foreach ($sql AS $fkey => $fquery) {
        $tmp_res =& $db->query($fquery);
        $tmp_res_arr = $tmp_res->fetchAll();
        foreach ($tmp_res_arr as $values) {
          if ($values[0] && $values[1]) { $result[$fkey][$values[0]] = $values[1]; }
        }
      }

      // Create non-distinct facets for age
      foreach ($this->locum_config['ages'] as $age_code => $age_name) {
        $sql = "SELECT COUNT(bnum) as age_sum FROM locum_avail_ages $where_str AND age = '$age_code'";
        $res =& $db->query($sql);
        $age_count = $res->fetchOne();
        if ($age_count) {
          $result['ages'][$age_code] = $age_count;
        }
      }

      // Create facets from availability cache
      $result['avail']['any'] = 0;
      foreach ($this->locum_config['branches'] as $branch_code => $branch_name) {
        $sql = "SELECT COUNT(DISTINCT(bnum)) FROM locum_avail_branches $where_str AND branch = '$branch_code' AND count_avail > 0";
        $res =& $db->query($sql);
        $avail_count = $res->fetchOne();
        if (!$avail_count) { $avail_count = 0; }
        $result['avail']['any'] = $result['avail']['any'] + $avail_count;
        if ($avail_count) {
          $result['avail'][$branch_code] = $avail_count;
        }
      }

      $db->disconnect();
      return $result;
    }
  }

  /**
   * Returns an array of item status info (availability, location, status, etc).
   *
   * @param string $bnum Bib number
   * @return array Detailed item availability
   */
  public function get_item_status($bnum, $force_refresh = FALSE, $cache_only = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum, $force_refresh);
    }

    $db = MDB2::connect($this->dsn);
    if ($cache_only) {
      // use the cache table, regardless of timestamp
      $sql = "SELECT * FROM locum_availability WHERE bnum = :bnum";
      $statement = $db->prepare($sql, array('integer'));
      $dbr = $statement->execute(array('bnum' => $bnum));
      if (PEAR::isError($result) && $this->cli) {
        echo "DB connection failed... " . $results->getMessage() . "\n";
      }
      $statement->Free();
      $cached = $dbr->NumRows();
    } else if (!$force_refresh && $this->locum_config['avail_cache']['cache']) {
      $this->locum_config['avail_cache']['cache_cutoff'];
      $cache_cutoff = date("Y-m-d H:i:s", (time() - (60 * $this->locum_config['avail_cache']['cache_cutoff'])));
      // check the cache table
      $sql = "SELECT * FROM locum_availability WHERE bnum = :bnum AND timestamp > '$cache_cutoff'";
      $statement = $db->prepare($sql, array('integer'));
      $dbr = $statement->execute(array('bnum' => $bnum));
      if (PEAR::isError($dbr) && $this->cli) {
        echo "DB connection failed... " . $dbr->getMessage() . "\n";
      }
      $statement->Free();
      $cached = $dbr->NumRows();
    }
    if ($cached) {
      $row = $dbr->fetchRow(MDB2_FETCHMODE_ASSOC);
      $avail_array = unserialize($row['available']);
      return $avail_array;
    }
    $bib = self::get_bib_item($bnum);
    $skiporder = ($bib['mat_code'] == 's');
    $status = $this->locum_cntl->item_status($bnum, $skiporder);
    $result['avail'] = 0;
    $result['total'] = count($status['items']);
    $result['libuse'] = 0;
    $result['holds'] = $status['holds'];
    $result['on_order'] = $status['on_order'];
    $result['orders'] = count($status['orders']) ? $status['orders'] : array();
    $result['nextdue'] = 0;

    $result['locations'] = array();
    $result['callnums'] = array();
    $result['ages'] = array();
    $result['branches'] = array();
    $loc_codes = array();
    if (count($status['items'])) {
      foreach ($status['items'] as &$item) {
        // Tally availability
        $result['avail'] += $item['avail'];
        // Tally libuse
        $result['libuse'] += $item['libuse'];

        // Parse Locations
        $result['locations'][$item['loc_code']][$item['age']]['avail'] += $item['avail'];
        $result['locations'][$item['loc_code']][$item['age']]['total']++;

        // Parse Ages
        $result['ages'][$item['age']]['avail'] += $item['avail'];
        $result['ages'][$item['age']]['total']++;

        // Parse Branches
        $result['branches'][$item['branch']]['avail'] += $item['avail'];
        $result['branches'][$item['branch']]['total']++;

        // Parse Callnums
        if ($item['callnum'] !== $bib['callnum'] && strstr($bib['callnum'], $item['callnum'])) {
          $item['callnum'] = $bib['callnum'];
        }
        $result['callnums'][$item['callnum']]['avail'] += $item['avail'];
        $result['callnums'][$item['callnum']]['total']++;

        // Determine next item due date
        if ($result['nextdue'] == 0 || ($item['due'] > 0 && $result['nextdue'] > $item['due'])) {
          $result['nextdue'] = $item['due'];
        }
        // Parse location code
        if (!in_array($item['loc_code'], $loc_codes) && trim($item['loc_code'])) {
          $loc_codes[] = $item['loc_code'];
        }
      }
    }
    $result['items'] = $status['items'];
    // Cache the result
    $avail_ser = serialize($result);
    $sql = "REPLACE INTO locum_availability (bnum, available) VALUES (:bnum, :available)";
    $statement = $db->prepare($sql, array('integer', 'text'));
    $dbr = $statement->execute(array('bnum' => $bnum, 'available' => $avail_ser));
    if (PEAR::isError($dbr) && $this->cli) {
      echo "DB connection failed... " . $dbr->getMessage() . "\n";
    }
    $statement->Free();

    // Store age cache
    $db->query("DELETE FROM locum_avail_ages WHERE bnum = '$bnum'");
    if (count($result['ages'])) {
      $sql = "INSERT INTO locum_avail_ages
      	(bnum, age, count_avail, count_total, timestamp)
      	VALUES (:bnum, :age, :count_avail, :count_total, NOW())
      ";
      $statement = $db->prepare($sql, array('integer', 'text', 'integer', 'integer'));
      foreach ($result['ages'] as $age => $age_info) {
        $dbr = $statement->execute(array(
        	'bnum' => $bnum,
        	'age' => $age,
        	'count_avail' => $age_info['avail'],
        	'count_total' => $age_info['total'])
        );
      }
      $statement->Free();
    }

    // Store branch info cache
    $db->query("DELETE FROM locum_avail_branches WHERE bnum = '$bnum'");
    if (count($result['branches'])) {
      $sql = "INSERT INTO locum_avail_branches
      	(bnum, branch, count_avail, count_total, timestamp)
      	VALUES (:bnum, :branch, :count_avail, :count_total, NOW())
      ";
      $statement = $db->prepare($sql, array('integer', 'text', 'integer', 'integer'));
      foreach ($result['branches'] as $branch => $branch_info) {
        $dbr = $statement->execute(array(
        	'bnum' => $bnum,
        	'branch' => $branch,
        	'count_avail' => $branch_info['avail'],
        	'count_total' => $branch_info['total'])
        );
      }
      $statement->Free();
    }

    return $result;
  }

  /**
   * Returns information about a bib title.
   *
   * @param string $bnum Bib number
   * @param boolean $get_inactive Return records whose active = 0
   * @return array Bib item information
   */
  public function get_bib_item($bnum, $get_inactive = FALSE) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum);
    }

/*
    $db = MDB2::connect($this->dsn);
    $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
    $utfprep = $db->query($utf);
    if ($get_inactive) {
      $sql = "SELECT * FROM locum_bib_items WHERE bnum = '$bnum' LIMIT 1";
    } else {
      $sql = "SELECT * FROM locum_bib_items WHERE bnum = '$bnum' AND active = '1' LIMIT 1";
    }
    $res = $db->query($sql);
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    $item_arr[0]['stdnum'] = preg_replace('/[^\d]/','', $item_arr[0]['stdnum']);
    return $item_arr[0];
*/
    $couch = new couchClient($this->couchserver,$this->couchdatabase);
    try {
        $doc = $couch->asArray()->getDoc($bnum);
      } catch ( Exception $e ) {
        return FALSE;
      }
    return $doc;
  }

  public function count_download($bnum, $type, $tracknum = NULL) {
    $couch = new couchClient($this->couchserver,$this->couchdatabase);
    try {
        $doc = $couch->getDoc($bnum);
      } catch ( Exception $e ) {
        return FALSE;
      }
    if($tracknum) {
      if($type == "play"){
        $count = $doc->tracks->$tracknum->plays ? $doc->tracks->$tracknum->plays : 0;
        $count++;
        $doc->tracks->$tracknum->plays = $count;
      } else if($type == "track") {
        $count = $doc->tracks->$tracknum->downloads ? $doc->tracks->$tracknum->downloads : 0;
        $count++;
        $doc->tracks->$tracknum->downloads = $count;
      }
    }
    else {
      $key = "download_".$type;
      $count = $doc->$key ? $doc->$key : 0;
      $count++;
      $doc->$key = $count;
    }
    $couch->storeDoc($doc);
  }

  public function get_cd_tracks($bnum) {
    $db =& MDB2::connect($this->dsn);
    $res =& $db->query("SELECT * FROM sample_tracks WHERE bnum = '$bnum' ORDER BY track");
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    return $item_arr;
  }
  public function get_upc($bnum) {
    $db =& MDB2::connect($this->dsn);
    $res =& $db->query("SELECT upc FROM sample_bibs WHERE bnum = '$bnum'");
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    return $item_arr[0];
  }

  /**
   * Returns information about an array of bib titles.
   *
   * @param array $bnum_arr Bib number array
   * @return array Bib item information for $bnum_arr
   */
  public function get_bib_items_arr($bnum_arr) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum_arr);
    }

    if (count($bnum_arr)) {
      $couch = new couchClient($this->couchserver,$this->couchdatabase);
      try {
        $doc = $couch->asArray()->include_docs(true)->keys($bnum_arr)->getView('sphinx','by_sphinxid');
      } catch ( Exception $e ) {
        return FALSE;
      }
/*
      $db =& MDB2::connect($this->dsn);
      $utf = "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'";
      $utfprep = $db->query($utf);
      $sql = 'SELECT * FROM locum_bib_items WHERE bnum IN (' . implode(', ', $bnum_arr) . ')';
      $res =& $db->query($sql);
      $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
      $db->disconnect();
      foreach ($item_arr as $item) {
        $item['stdnum'] = preg_replace('/[^\d]/','', $item['stdnum']);
        $bib[(string) $item['bnum']] = $item;
      }
*/
    }
    return $doc['rows'];
  }

  public function get_bib_items($bnum_arr) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($bnum_arr);
    }

    if (count($bnum_arr)) {
      $couch = new couchClient($this->couchserver,$this->couchdatabase);
      try {
        $doc = $couch->asArray()->include_docs(true)->keys($bnum_arr)->getAllDocs();
      } catch ( Exception $e ) {
        return FALSE;
      }
    }
    return $doc['rows'];
  }

	/**
	 * Create a new patron in the ILS
	 *
	 * Note: this may not be supported by all connectors. Further, it may turn out that
	 * different ILS's require different data for this function. Thus the $patron_data
	 * parameter is an array which can contain whatever is appropriate for the current ILS.
	 *
	 * @param array $patron_data
	 * @return var
	 */
	public function create_patron($patron_data) {
		if (!is_array($patron_data) || !count($patron_data)) {
			return false;
		}
		$new_patron = $this->locum_cntl->create_patron($patron_data);
		return $new_patron;
	}

	/**
	 * Returns an array of patron information
	 *
	 * @param string $pid Patron barcode number or record number
	 * @param string $user_key for use with Sirsi
	 * @param string $alt_id for use with Sirsi
	 * @return boolean|array Array of patron information or FALSE if login fails
	 */
	public function get_patron_info($pid = null, $user_key = null, $alt_id = null) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($pid, $user_key, $alt_id);
    }

		$patron_info = $this->locum_cntl->patron_info($pid, $user_key, $alt_id);
		return $patron_info;
	}

	/**
	 * Update user info in ILS.
	 * Note: this may not be supported by all connectors.
	 *
	 * @param string $pid Patron barcode number or record number
	 * @param string $email address to set
	 * @param string $pin to set
	 * @return boolean|array
	 */
	public function set_patron_info($pid, $email = null, $pin = null) {
		$success = $this->locum_cntl->set_patron_info($pid, $email, $pin);
		return $success;
	}

  /**
   * Returns an array of patron checkouts
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function get_patron_checkouts($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    $patron_checkouts = $this->locum_cntl->patron_checkouts($cardnum, $pin);
    foreach($patron_checkouts as &$patron_checkout) {
      // lookup bnum from inum
      if (!$patron_checkout['bnum']) {
        $patron_checkout['bnum'] = self::inum_to_bnum($patron_checkout['inum']);
      }
      if ($patron_checkout['ill'] == 0) {
        $bib = self::get_bib_item($patron_checkout['bnum'], TRUE);
        $patron_checkout['bib'] = $bib;
        $patron_checkout['avail'] = self::get_item_status($patron_checkout['bnum'], FALSE, TRUE);
        $patron_checkout['scraped_title'] = $patron_checkout['title'];
        $patron_checkout['title'] = $bib['title'];
        if ($bib['title_medium']) {
          $patron_checkout['title'] .= ' ' . $bib['title_medium'];
        }
        if($bib['author'] != '') {
          $patron_checkout['author'] = $bib['author'];
        }
        $patron_checkout['addl_author'] = $bib['addl_author'];
        //if($bib['author']) {$patron_checkout['author'] = $bib['author']};
      }
    }
    return $patron_checkouts;
  }

  /**
   * Returns an array of patron checkouts for history
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array $last_record Array containing: 'bnum' => Bib num, 'date' => Date of last record harvested.
   *              It will return everything after that record if this value is passed
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function get_patron_checkout_history($cardnum, $pin = NULL, $last_record = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    return $this->locum_cntl->patron_checkout_history($cardnum, $pin, $action);
  }

  /**
   * Opts patron in or out of checkout history
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron checkouts or FALSE if $barcode doesn't exist
   */
  public function set_patron_checkout_history($cardnum, $pin = NULL, $action = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $action);
    }

    return $this->locum_cntl->patron_checkout_history_toggle($cardnum, $pin, $action);
  }

  /**
   * Deletes patron checkout history off the ILS server
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param string $action NULL = do nothing, 'all' = delete all records, 'selected' = Delete records in $vars array
   * @param array $vars array of variables referring to records to delete (optional)
   * @param array $last_record Array containing: 'bnum' => Bib num, 'date' => Date of last record harvested
   */
  public function delete_patron_checkout_history($cardnum, $pin = NULL, $action = NULL, $vars = NULL, $last_record = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

  }

  /**
   * Returns an array of patron holds
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron holds or FALSE if login fails
   */
  public function get_patron_holds($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    $patron_holds = $this->locum_cntl->patron_holds($cardnum, $pin);
    if (count($patron_holds)) {
      foreach ($patron_holds as &$patron_hold) {
        // lookup bnum from inum
        if (!$patron_hold['bnum']) {
          $patron_hold['bnum'] = self::inum_to_bnum($patron_hold['inum']);
        }
        if ($patron_hold['ill'] == 0) {
          $bib = self::get_bib_item($patron_hold['bnum'], TRUE);
          $patron_hold['bib'] = $bib;
          //$patron_hold['avail'] = self::get_item_status($patron_checkout['bnum'], FALSE, TRUE);
          $patron_hold['scraped_title'] = $patron_hold['title'];
          $patron_hold['title'] = $bib['title'];
          if ($bib['title_medium']) {
            $patron_hold['title'] .= ' ' . $bib['title_medium'];
          }
          if($bib['author'] != '') {
            $patron_hold['author'] = $bib['author'];
          }
          $patron_hold['addl_author'] = $bib['addl_author'];
          //if($bib['author']) {$patron_checkout['author'] = $bib['author']};
        }
      }
    }
    return $patron_holds;
  }

  /**
   * Renews items and returns the renewal result
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array Array of varname => item numbers to be renewed, or NULL for everything.
   * @return boolean|array Array of item renewal statuses or FALSE if it cannot renew for some reason
   */
  public function renew_items($cardnum, $pin = NULL, $items = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $items);
    }

    $renew_status = $this->locum_cntl->renew_items($cardnum, $pin, $items);
    return $renew_status;
  }

  /**
   * Updates holds/reserves
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array $cancelholds Array of varname => item/bib numbers to be cancelled, or NULL for everything.
   * @param array $holdfreezes_to_update Array of updated holds freezes.
   * @param array $pickup_locations Array of pickup location changes.
   * @return boolean TRUE or FALSE if it cannot cancel for some reason
   */
  public function update_holds($cardnum, $pin = NULL, $cancelholds = array(), $holdfreezes_to_update = array(), $pickup_locations = array(), $suspend_changes = array()) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $cancelholds, $holdfreezes_to_update, $pickup_locations, $suspend_changes);
    }

    return $this->locum_cntl->update_holds($cardnum, $pin, $cancelholds, $holdfreezes_to_update, $pickup_locations, $suspend_changes);
  }

  /**
   * Places holds
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $bnum Bib item record number to place a hold on
   * @param string $varname additional variable name (such as an item number for item-level holds) to place a hold on
   * @param string $pin Patron pin/password
   * @param string $pickup_loc Pickup location value
   * @return boolean TRUE or FALSE if it cannot place the hold for some reason
   */
  public function place_hold($cardnum, $bnum, $varname = NULL, $pin = NULL, $pickup_loc = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $bnum, $varname, $pin, $pickup_loc);
    }

    $request_status = $this->locum_cntl->place_hold($cardnum, $bnum, $varname, $pin, $pickup_loc);
    if ($request_status['success']) {
      $db =& MDB2::connect($this->dsn);
      $db->query("INSERT INTO locum_holds_placed VALUES ('$bnum', NOW())");
    }
    return $request_status;
  }

  /**
   * Returns an array of patron fines
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @return boolean|array Array of patron holds or FALSE if login fails
   */
  public function get_patron_fines($cardnum, $pin = NULL) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin);
    }

    $patron_fines = $this->locum_cntl->patron_fines($cardnum, $pin);
    return $patron_fines;
  }

  /**
   * Pays patron fines.
   * $payment_details structure:
   * [varnames]     = An array of varnames to id which fines to pay.
   * [total]      = payment total.
   * [name]      = Name on the credit card.
   * [address1]    = Billing address.
   * [address2]    = Billing address.  (opt)
   * [city]      = Billing address city.
   * [state]      = Billing address state.
   * [zip]      = Billing address zip.
   * [email]      = Cardholder email address.
   * [ccnum]      = Credit card number.
   * [ccexpmonth]    = Credit card expiration date.
   * [ccexpyear]    = Credit card expiration year.
   * [ccseccode]    = Credit card security code.
   *
   * @param string $cardnum Patron barcode/card number
   * @param string $pin Patron pin/password
   * @param array payment_details
   * @return array Payment result
   */
  public function pay_patron_fines($cardnum, $pin = NULL, $payment_details) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($cardnum, $pin, $payment_details);
    }

    $payment_result = $this->locum_cntl->pay_patron_fines($cardnum, $pin, $payment_details);
    return $payment_result;
  }

  /*
   * Returns an array of random bibs.
   */
  public function get_bib_numbers($limit = 10) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($limit);
    }

    $db =& MDB2::connect($this->dsn);
    $res =& $db->query("SELECT bnum FROM locum_bib_items ORDER BY RAND() LIMIT $limit");
    $item_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    $db->disconnect();
    $bnums = array();
    foreach ($item_arr as $item) {
      $bnums[] = $item['bnum'];
    }
    return $bnums;
  }

  public function inum_to_bnum($inum, $force_refresh = FALSE) {
    $inum = intval($inum);
    $db = MDB2::connect($this->dsn);
    $cache_cutoff = date("Y-m-d H:i:s", time() - 60 * 60 * 24 * 7); // 1 week
    $cached = FALSE;

    if (!$force_refresh) {
      // check the cache table
      $sql = "SELECT * FROM locum_inum_to_bnum WHERE inum = :inum AND timestamp > '$cache_cutoff'";
      $statement = $db->prepare($sql, array('integer'));
      $result = $statement->execute(array('inum' => $inum));
      if (PEAR::isError($result) && $this->cli) {
        echo "DB connection failed... " . $results->getMessage() . "\n";
      }
      $statement->Free();
      $cached = $result->NumRows();
    }
    if ($cached) {
      $row = $result->fetchRow(MDB2_FETCHMODE_ASSOC);
      $bnum = $row['bnum'];
    } else {
      // get fresh info from the catalog
      $iii_webcat = $this->locum_config[ils_config][ils_server];
      $url = 'http://' . $iii_webcat . '/record=i' . $inum;
      $bib_page_raw = utf8_encode(file_get_contents($url));
      preg_match('/marc~b([0-9]*)/', $bib_page_raw, $bnum_raw_match);
      $bnum = $bnum_raw_match[1];

      if ($bnum) {
        $sql = "REPLACE INTO locum_inum_to_bnum (inum, bnum) VALUES (:inum, :bnum)";
        $statement = $db->prepare($sql, array('integer', 'integer'));
        $result = $statement->execute(array('inum' => $inum, 'bnum' => $bnum));
        if (PEAR::isError($result) && $this->cli) {
          echo "DB connection failed... " . $results->getMessage() . "\n";
        }
        $statement->Free();
      }
    }
    return $bnum;
  }

  public function get_uid_from_token($token) {
    $db = MDB2::connect($this->dsn);
    $sql = "SELECT * FROM locum_tokens WHERE token = '$token' LIMIT 1";
    $res = $db->query($sql);
    $patron_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    return $patron_arr[0]['uid'];
  }

  public function get_token($uid) {
    $db = MDB2::connect($this->dsn);
    $sql = "SELECT * FROM locum_tokens WHERE uid = '$uid' LIMIT 1";
    $res = $db->query($sql);
    $patron_arr = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
    return $patron_arr[0]['token'];
  }

  public function set_token($uid) {
    $db = MDB2::connect($this->dsn);
    $random = mt_rand();
    $token = md5($uid.$random);
    $sql = "REPLACE INTO locum_tokens (uid, token) VALUES (:uid, '$token')";
    $statement = $db->prepare($sql, array('integer'));
    $result = $statement->execute(array('uid' => $uid));
    return $token;
  }

  /************ External Content Functions ************/

  /**
   * Formulates "Did you mean?" I may move to the Yahoo API for this..
   *
   * @param string $str String to check
   * @return string|boolean Either returns a string suggestion or FALSE
   */
  public function yahoo_suggest($str) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($str);
    }
    $search_string = rawurlencode($str);
    $url = 'http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20search.spelling%20where%20query%3D%22'.$search_string.'%22&format=json';
    $suggest_obj = json_decode(file_get_contents($url));
    if (trim($suggest_obj->query->results->suggestion)) {
      return trim($suggest_obj->query->results->suggestion);
    } else {
      return FALSE;
    }
  }

  /*
   * Client-side version of get_syndetics().  Does not harvest, only checks the database.
   */
  public function get_syndetics($isbn) {
    if (is_callable(array(__CLASS__ . '_hook', __FUNCTION__))) {
      eval('$hook = new ' . __CLASS__ . '_hook;');
      return $hook->{__FUNCTION__}($isbn);
    }

    // Strip out yucky non-isbn characters
    $isbn = preg_replace('/[^\dX]/', '', $isbn);

    $cust_id = $this->locum_config['api_config']['syndetic_custid'];
    if (!$cust_id) {
      return NULL;
    }

    $valid_hits = array(
      'TOC'         => 'Table of Contents',
      'BNATOC'      => 'Table of Contents',
      'FICTION'     => 'Fiction Profile',
      'SUMMARY'     => 'Summary / Annotation',
      'DBCHAPTER'   => 'Excerpt',
      'LJREVIEW'    => 'Library Journal Review',
      'PWREVIEW'    => 'Publishers Weekly Review',
      'SLJREVIEW'   => 'School Library Journal Review',
      'CHREVIEW'    => 'CHOICE Review',
      'BLREVIEW'    => 'Booklist Review',
      'HORNBOOK'    => 'Horn Book Review',
      'KIRKREVIEW'  => 'Kirkus Book Review',
      'ANOTES'      => 'Author Notes'
    );

    $db =& MDB2::connect($this->dsn);
    $res = $db->query("SELECT links FROM locum_syndetics_links WHERE isbn = '$isbn' LIMIT 1");
    $dbres = $res->fetchAll(MDB2_FETCHMODE_ASSOC);

    if ($dbres[0]['links']) {
      $links = explode('|', $dbres[0]['links']);
    } else {
      return FALSE;
    }

    if ($links) {
      foreach ($links as $link) {
        $link_result[$valid_hits[$link]] = 'http://www.syndetics.com/index.aspx?isbn=' . $isbn . '/' . $link . '.html&client=' . $cust_id;
      }
    }
    $db->disconnect();
    return $link_result;
  }

  public function make_donation($donate_form_values) {
    return $this->locum_cntl->make_donation($donate_form_values);
  }

}
