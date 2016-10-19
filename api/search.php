<?php
/**
handle post requests for data extraction tool web page
*/

set_time_limit(300);

$m = new MongoClient();

$output = new Output();

if (empty($_POST['call'])) {
    $output->error('no call provided')->send();
    exit;
}


$call = $_POST['call'];

if (!function_exists($call)) {
    $output->error('invalid call')->send();
    exit;
}


// run specified function
call_user_func($call, $_POST);

//  valid $call options:
//      "add_request"
//      "get_requests"
//      "get_boundaries"
//      "get_relevant_datasets"
//      "get_boundary_geojson"
//      "get_filter_count"


// ===========================================================================


/**
return post requests

returns array containing:
    status (success, error)
    error (error info, if status == "error")
    params (from original post)
    data (return data)
*/
class Output {
    private $output = [
        'status'=> 'success',
        'error'=> null,
        'params'=> null,
        'data'=> null
    ];

    /**
    add post params to response if they exist
    */
    public function __construct() {
        if (!empty($_POST)) {
            $this->output['params'] = $_POST;
        }
    }

    /**
    add error status and information to response
    */
    public function error($error = null) {
        $this->output['status'] = 'error';
        $this->output['error'] = $error;
        return $this;
    }

    /**
    add data to response and send response
    */
    public function send($data = null) {
        $this->output['data'] = $data;
        echo json_encode($this->output);
    }
}


// ===========================================================================
// functions for basic type validation of data being input into
// mongo collections

function is_clean_val($input) {
    if (!is_string($input) && !is_numeric($input) && !is_bool($input)) {
        return False;
    }
    if (is_string($input) && strpos("\0", $input) !== False) {
        return False;
    }
    return True;
}

// https://stackoverflow.com/questions/173400/how-to-check-if-php-
//      array-is-associative-or-sequential/173479#173479
function is_assoc($arr) {
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function is_clean_array($input) {
    if (!is_array($input)) {
        return False;
    }
    if (is_assoc($input)) {
        return False;
    }
    foreach ($input as $i) {
        if (!is_clean_val($i)) {
            return False;
        }
    }
    return True;
}


/**
Explode array using given delimiter, drop orignial delimited
value from array and add new exploded values back into array

array = array to explode (required)
delim = delimiter (default to pipe "|")
unique = only keep unique values from exploded array (default true)
sort = sort exploded array (default ascending sort) (default true)
reverse = sort reverse (sort must be true) (default false)
*/
function explode_array($arr, $delim = "|", $unique = true,
                       $sort = true, $reverse = false) {

    $arr = array_values($arr);
    for ($i=0; $i<count($arr); $i++) {
        if (strpos($arr[$i], $delim) !== false) {
            $new = explode($delim, $arr[$i]);
            $arr[$i] = array_shift($new);
            $arr = array_merge($arr, $new);
        }
    }

    if ($unique) {
        $arr = array_unique($arr);
    }

    if ($sort) {
        if ($reverse) {
            rsort($arr);
        } else {
            sort($arr);
        }
    }

    return $arr;
}

/**
generic function to verify array matches certain type
eg, all([1,2,3], 'is_int') would return true
(could also use any valid filter function, not just for type val)

http://stackoverflow.com/questions/3559542/
more-concise-way-to-check-to-see-if-an-array-contains-only-numbers-integers
*/
function all($array, $predicate) {
    return array_filter($array, $predicate) === $array;
}


function is_not_nan($val) {
    return !is_nan($val);
}

// ===========================================================================
// functions for post requests


/**
find and return text/field info

db:col = asdf:data

post fields
    None

returns
    json string representing dictionary where keys are cccccccc
     and values are cccccccccccccc

*/
function get_info($data) {
    global $output, $m;

    $c_config = $m->selectDB('info')->selectCollection('config');
    $config_options = $c_config->findOne();
    $info = $config_options['det'];

    $output->send($info);
    return 0;
}


/**
get request limits

returns
    json object string with limits for each type and total
    (value of -1 means no limit)
*/
function get_request_limits($data) {
    global $output, $m;

    $c_config = $m->selectDB('info')->selectCollection('config');
    $config_options = $c_config->findOne();
    $request_limits = $config_options['det']['limits'];

    $output->send($request_limits);
    return 0;
}


/**
find and return all eligible boundaries

db:col = asdf:data

post fields
    None

returns
    json string representing dictionary where keys are boundary
    group names and values are lists of boundary docs for each
    boundary in group

*/
function get_boundaries($data) {
    global $output, $m;

    $c_asdf = $m->selectDB('asdf')->selectCollection('data');

    $query = array('type' => 'boundary', 'active' => 1);

    // $fields = array(
    //     'base' => true,
    //     'name' => true,
    //     'title' => true,
    //     'description' => true,
    //     'version' => true,
    //     'options.group' => true,
    //     'options.group_class' => true,
    //     'options.group_title' => true,
    //     'resources.path' => true,
    //     'extras' => true
    // );
    // $cursor = $c_asdf->find($query, $fields);


    $cursor = $c_asdf->find($query);
    //// $cursor->snapshot();

    $result = array();
    foreach ($cursor as $doc) {
        $result[$doc['options']['group']][] = $doc;
    }

    $output->send($result);
    return 0;
}


/**
find and returns contents of simplified boundary geojson
for web map

db:col = asdf:data

post fields
    name : boundary

returns
    simplified geojson as json
*/
function get_boundary_geojson($data) {
    global $output, $m;

    $name = $data['name'];

    if (!is_clean_val($name)) {
        $output->error('invalid inputs')->send();
        return 0;
    }

    $c_asdf = $m->selectDB('asdf')->selectCollection('data');

    $query = array('type' => 'boundary', 'name' => $name);

    $fields = array(
        'base' => true
    );

    $result = $c_asdf->findOne($query, $fields);

    $base = $result['base'];

    $file = $base . "/simplified.geojson";

    $result = file_get_contents($file);

    $output->send($result);
    return 0;
}


/**
find relevant datasets for specified boundary group

db:col = asdf:data

post fields
    group : boundary group

returns
    json array containg datasets objects
*/
function get_relevant_datasets($data) {
    global $output, $m;

    if (empty($data['group'])) {
        $output->error('missing group')->send();
        return 0;
    }

    $group = $data['group'];

    if (!is_clean_val($group)) {
        $output->error('invalid inputs')->send();
        return 0;
    }

    $c_asdf = $m->selectDB('asdf')->selectCollection('data');


    // prep spatial query based on boundary bounds
    $bnd_info = $c_asdf->findOne([
        'type'=> 'boundary',
        'options.group'=> $group,
        'options.group_class'=> 'actual'
    ]);

    $bnd_coords = $bnd_info['spatial']['coordinates'];

    $bnd_minx = $bnd_coords[0][0][0];
    $bnd_miny = $bnd_coords[0][1][1];
    $bnd_maxx = $bnd_coords[0][2][0];
    $bnd_maxy = $bnd_coords[0][0][1];

    $spatial_query = [
        'locations.longitude' => ['$gte' => $bnd_minx, '$lte' => $bnd_maxx],
        'locations.latitude' => ['$gte' => $bnd_miny, '$lte' => $bnd_maxy]
    ];


    // get valid datasets from tracker
    $c_tracker = $m->selectDB('trackers')->selectCollection($group);

    $tracker_query = array('status' => 1);

    $tracker_fields = array(
        'name' => true,
    );

    $tracker_cursor = $c_tracker->find($tracker_query, $tracker_fields);

    // put tracker results into array
    $list = array();
    foreach ($tracker_cursor as $doc) {
        $list[] = $doc['name'];
    }

    $query = array(
        'name' => array('$in' => $list),
        'temporal.type' => array('$in' => array('year', 'None')),
        'type' => array('$in' => array('release', 'raster')),
        'active' => 1
    );

    $cursor = $c_asdf->find($query);


    $result = array();


    $c_config = $m->selectDB('info')->selectCollection('config');
    $config_options = $c_config->findOne();
    $active_release_fields = $config_options['det']['fields'];


    foreach ($cursor as $doc) {

        if ($doc['type'] == "release") {

            $c_releases = $m->selectDB('releases')->$doc['name'];

            $release_query = ['is_geocoded' => 1];
            $release_query = array_merge($release_query, $spatial_query);

            $doc['fields'] = [];

            foreach ($active_release_fields as $info) {

                $f = $info['field'];

                $doc['fields'][$f] = $info;

                $query_name = $f;
                if (in_array($info['parent'], ['locations', 'transactions'])) {
                    $query_name = $info['parent'] . '.' . $f;
                }

                // get initial set of distinct fields
                $tmp_distinct = $c_releases->distinct($query_name, $release_query);
                $tmp_distinct = array_filter($tmp_distinct, 'is_not_nan');
                $tmp_distinct = explode_array($tmp_distinct, "|");

                if (count($tmp_distinct) > 1) {
                    $doc['fields'][$f]['distinct'] = $tmp_distinct;
                } else {
                    unset($doc['fields'][$f]);
                }
            }

            $result[] = $doc;

        } else if ($doc['type'] == "raster") {
            $result[] = $doc;

        }

    }

    $output->send($result);
    return 0;
}


/**
get counts for release dataset based on filter

db:col = releases:*

post fields
    filter : fields and filters

returns
    number of projects, locations, and array assoc array containing
    distinct values for each search field based on current search params
    loc1?, loc2?
*/
function get_filter_count($data) {
    global $output, $m;

    if (empty($_POST['filter'])) {
        $output->error('missing filter')->send();
        return 0;
    }

    $filter = $data['filter'];

    $has_group = false;
    // prep spatial query based on boundary bounds
    if (!empty($_POST['group'])) {
        $has_group = true;

        $group = $data['group'];

        $c_asdf = $m->selectDB('asdf')->selectCollection('data');
        $bnd_info = $c_asdf->findOne([
            'type'=> 'boundary',
            'options.group'=> $group,
            'options.group_class'=> 'actual'
        ]);

        $bnd_coords = $bnd_info['spatial']['coordinates'];

        $bnd_minx = $bnd_coords[0][0][0];
        $bnd_miny = $bnd_coords[0][1][1];
        $bnd_maxx = $bnd_coords[0][2][0];
        $bnd_maxy = $bnd_coords[0][0][1];

        $spatial_query = [
            'locations.longitude' => ['$gte' => $bnd_minx, '$lte' => $bnd_maxx],
            'locations.latitude' => ['$gte' => $bnd_miny, '$lte' => $bnd_maxy]
        ];

    }

    // validate $filter
    foreach ($filter as $k => $v) {
        if (in_array($k, array("dataset"))) {
            // dataset and type field must be str
            if (!is_clean_val($filter[$k])) {
                $output->error('invalid inputs')->send();
                return 0;
            }
        } else {
            // // all other fields must be arrays of strings
            // if (!is_clean_array($filter[$k])) {
            //     $output->error('invalid inputs')->send();
            //     return 0;
            // }
        }
    }


    $c_config = $m->selectDB('info')->selectCollection('config');
    $config_options = $c_config->findOne();
    $active_release_fields = $config_options['det']['fields'];

    $parent_lookup = [];
    foreach ($active_release_fields as $info) {
        $f = $info['field'];

        $query_name = $f;
        if (in_array($info['parent'], ['locations', 'transactions'])) {
            $query_name = $info['parent'] . '.' . $f;
        }

        $parent_lookup[$f] = $query_name;
    }


    $c_release = $m->selectDB('releases')->selectCollection($filter['dataset']);


    $regex_map = function($value) {
        $value = str_replace('(', '\(', $value);
        $value = str_replace(')', '\)', $value);
        return new MongoRegex("/.*" . $value . ".*/");
    };


    $distinct_fields = [];

    $count_query = ['is_geocoded' => 1];
    if ($has_group) {
        $count_query = array_merge($count_query, $spatial_query);
    }

    foreach ($filter['filters'] as $k => $v) {

        $ka = $parent_lookup[$k];

        $distinct_query = ['is_geocoded' => 1];
        if ($has_group) {
            $distinct_query = array_merge($distinct_query, $spatial_query);
        }

        // prepare query
        if (!in_array("All", $v)) {

            if (in_array($k, ['total_commitments', 'total_disbursements'])) {

                $vals = array_map('floatval', $v);
                $tmp_search = [
                    '$gte' => min($vals),
                    '$lte' => max($vals)
                ];

            } else if (all($v, 'is_numeric')) {
                $tmp_search = [
                    '$in' => array_merge(
                        array_map('intval', $v), array_map('strval', $v)
                    )
                ];
            } else {
                $tmp_search = [
                    '$in' => array_map($regex_map, $v)
                ];
            }
            $count_query[$ka] = $tmp_search;
            $distinct_query[$ka] = $tmp_search;
        }

        // get distinct fields for given query
        foreach ($filter['filters'] as $kx => $vx) {
            if ($kx != $k) {

                $kxa = $parent_lookup[$kx];

                // if ($kx == "transaction_year") {
                //     $tmp_distinct = $c_release->distinct($kxa, $distinct_query);
                //     sort($tmp_distinct);

                // } else {
                    $tmp_distinct = $c_release->distinct($kxa, $distinct_query);
                    $tmp_distinct = array_filter($tmp_distinct, 'is_not_nan');
                    $tmp_distinct = explode_array($tmp_distinct, "|");
                // }

                if (!array_key_exists($kxa, $distinct_fields)){
                    $distinct_fields[$kx] = $tmp_distinct;
                } else {
                    $distinct_fields[$kx] = array_values(array_intersect(
                        $distinct_fields[$kx], $tmp_distinct));
                }
            }
        }

    }


    // get project and location counts
    $project_cursor = $c_release->find($count_query);
    $projects = $project_cursor->count();

    // // get number of locations (non geocoded)
    // $location_query_1 = $count_query;
    // $location_query_1['is_geocoded'] = 0;
    // $location_cursor_1 = $c_release->find($location_query_1);
    // $location_count_1 = $location_cursor_1->count();

    // get number of locations (geocoded with locations unwind)
    $location_query_2 = $count_query;
    // $location_query_2['is_geocoded'] = 1;

    $location_aggregate = array();
    $location_aggregate[] = array('$match' => $location_query_2);
    $location_aggregate[] = array(
        '$project' => array("project_id"=>1, 'locations'=>1)
    );
    $location_aggregate[] = array('$unwind' => '$locations');

    $location_cursor_2 = $c_release->aggregate($location_aggregate);
    $location_count_2 = count($location_cursor_2["result"]);


    $locations = $location_count_2;
    // $locations = $location_count_1 + $location_count_2;


    $result = array(
        "projects" => $projects,
        "locations" => $locations,
        "distinct" => $distinct_fields
        // "location_count_1" => $location_count_1,
        // "location_count_2" => $location_count_2
    );

    $output->send($result);
    return 0;
}


/**
inserts request object as document in det->queue mongo db/collection

db:col = det:queue

post fields
    request : json string for request fields

returns
    unique mongoid assigned to request
*/
function add_request($data) {
    global $output, $m;

    $request = json_decode($data['request'], true);

    // validate $request
    //

    $request['info'] = "";
    $request['status'] = -1;
    $request['priority'] = 0;
    $request['stage'] = [
        0 => ['name' => 'submitted', 'time' => time()],
        1 => ['name' => 'prepared', 'time' => 0],
        2 => ['name' => 'processed', 'time' => 0],
        3 => ['name' => 'completed', 'time' => 0]
    ];

    $c_queue = $m->selectDB('det')->selectCollection('queue');

    // write request json to request db
    $c_queue->insert($request);

    // get unique mongoid which will serve as request id
    $request_id = (string) $request->_id;

    $result =  [
        'request_id' => $request_id,
        'request' => $request
    ];

    $output->send($result);
    return 0;
}


/**
generate request information for det status page

db:col = det:queue

post fields
    search_type : "id" or "email"
    search_val : id or email  value

returns
    det queue collection docs matching search_type and search_val
*/
function get_requests($data) {
    global $output, $m;

    $search_type = $data['search_type'];
    $search_val = $data['search_val'];


    if (!is_clean_val($search_type) || !is_clean_val($search_val)) {
        $output->error('invalid inputs')->send([]);
        return 0;
    }

    $c_queue = $m->selectDB('det')->selectCollection('queue');

    if ($search_type == "email") {
        $query = array('email' => $search_val);
        $cursor = $c_queue->find($query);

    } else if ($search_type == "id") {
        try {
            $query = array('_id' => new MongoId($search_val));
            $cursor = $c_queue->find($query);
        } catch (Exception $e) {
            $output->error('invalid id')->send([]);
            return 0;
        }

    } else {
        $output->error('invalid search type')->send([]);
        return 0;
    }

    $result = iterator_to_array($cursor, false);
    $output->send($result);
    return 0;
}


?>