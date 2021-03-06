<?php

// @todo Turn this down in production code.
error_reporting(E_ALL);

/** @var MySQLiDriver $database */
// Set these to -something- in case the include fails
$API_PATH          = '';
$SQL_PREFIX        = '';
$CONNECTION_STRING = '';
$requestPath       = '';
$requestQuery      = [ ];
$database          = null;
$path              = null;
$scope             = null;
$object            = null;
$permissions       = null;

// Accomodate local testing that can't go above webroot.
// @todo Take this out of production code.
/** @noinspection PhpIncludeInspection */
if (file_exists('../config.inc.php')) {
    /** @noinspection PhpIncludeInspection */
    require '../config.inc.php';
}
else {
    require 'config.inc.php';
}
require 'exception.php';
require 'MySQLiDriver.php';

main();
exit();

/**
 * Builds our environment and populates global variables.
 *
 * Rebuilds query string data because some environments consider the entire
 * path to be in the query string. Parses REQUEST_URI into the actual API
 * call. Connects to database. Validates API key and gets privilege scope.
 * Does initial API dispatch to handlers.
 *
 * @throws ApiKeyNotPrivilegedException
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws WhatTheHeckIsThisException
 */
function main() {
    global $API_PATH, $CONNECTION_STRING, $requestPath, $requestQuery,
           $database, $path, $object, $scope, $permissions;

    // @todo This should probably be sanitized/validated against a whitelist like event creation and searching are.
    $method  = $_SERVER['REQUEST_METHOD'];
    $pattern = '/^\/' . preg_quote($API_PATH, '/') . '([a-z0-9]{40}|)\/?(\w+$|\w+(?=[\/]))\/?(.+)?/';

    if (strpos($_SERVER['REQUEST_URI'], "?") > 0) {
        list($requestPath, $queryString) = explode('?', $_SERVER['REQUEST_URI']);
    }
    else {
        $requestPath = $_SERVER['REQUEST_URI'];
        $queryString = '';
    }

    // @todo This was added for local debugging and can be removed.
    $requestPath = str_replace("LeagleEye_API/", "", $requestPath);

    $requestPath = strtolower($requestPath);
    $queryString = urldecode($queryString);

    foreach (explode('&', $queryString . '&') as $entry) {
        if (strlen($entry) > 0) {
            list($key, $value) = explode('=', $entry);
            $requestQuery[ $key ] = $value;
        }
    }

    /* Depending on server configuration,
       the path may or more not start with /
    */

    if (substr($requestPath, 0, 1) != '/') {
        $requestPath = "/" . $requestPath;
    }

    preg_match($pattern, $requestPath, $matches);

    switch (count($matches)) {
        /** @noinspection PhpMissingBreakStatementInspection */
        case 4:
            $path = explode("/", $matches[3]);
        /** @noinspection PhpMissingBreakStatementInspection */
        case 3:
            $object = $matches[2];
        case 2:
            $apiKey = $matches[1];
            break;
    }

    $action = 'api_' . strtoupper($object . '_' . $method) . '_dispatch';
    $action = filter_var($action, FILTER_SANITIZE_EMAIL);


    $mysqlCredentials = [ ];

    try {
        foreach (explode(';', $CONNECTION_STRING) as $entry) {
            list($key, $value) = explode('=', $entry);
            $mysqlCredentials[ $key ] = $value;
        }

        $database = new MySQLiDriver($mysqlCredentials);
    } catch (Exception $e) {
        sendResponse([ $e ]);
    }

    if (isset($apiKey) && strlen($apiKey) == 40) {
        $sqlQuery = <<<EOF
SELECT
    is_expired,
    scope,
    scopekey,
    ALLOW_RENEW,
    ALLOW_LIST,
    ALLOW_UPLOAD,
    ALLOW_EDIT,
    ALLOW_SEARCH,
    ALLOW_APIKEY_CREATE
FROM
    tbl__apikeys
WHERE
    apikey=(?)
LIMIT 1
EOF;

        $permissions = null;
        try {
            $permissions = $database->select($sqlQuery, [ [ 's' => $apiKey ] ])[0];
        } catch (DatabaseNothingSelectedException $e) {
            throw new ApiKeyNotPrivilegedException([ $apiKey ], $e);
        }

        $field  = '';
        $lookup = '';

        $parameter = '';
        if ($permissions['is_expired']) {
            $scope = array();
        }
        else {
            switch ($permissions['scope']) {
                case 'GLOBAL':
                    $scope['product'] = "*";
                    $scope['segment'] = "*";
                    $scope['event']   = "*";
                    break;
                case 'PRODUCT':
                    $scope['product'] = $permissions['scopekey'];
                    $scope['segment'] = "*";
                    $scope['event']   = "*";
                    break;
                case 'SEGMENT':
                    $scope['segment'] = $permissions['scopekey'];
                    $scope['event']   = "*";
                    $field            = 'productkey';
                    $table            = 'segments';
                    $lookup           = 'segmentkey';
                    $parameter        = $permissions['scopekey'];
                    break;
                case 'EVENT':
                    $scope['product'] = "*";
                    $scope['segment'] = "*";
                    $field            = "session";
                    $table            = 'events';
                    $lookup           = 'eventkey';
                    $parameter        = $permissions['scopekey'];
                    break;
                default:
                    $response = [
                        'status' => [
                            'code' => 500
                        ],
                        'error'  => [
                            'message' => 'Database Sanity Error'
                        ],
                        'trace'  => [ $database ]
                    ];
                    throw new WhatTheHeckIsThisException($response);
                    break;
            }

            if (isset($table)) {
                $sqlQuery = <<<EOF
    SELECT
        {$field}
    FROM
        tbl__{$table}
    WHERE
        {$lookup}=?
    LIMIT 1
EOF;

                try {
                    $scopeResult = $database->select($sqlQuery, [ [ "i" => $parameter ] ])[0];
                } catch (DatabaseNothingSelectedException $e) {
                    throw new WhatTheHeckIsThisException([ $sqlQuery, 'parameter' => $parameter, $e ]);
                }

                if (isset($scopeResult['productkey'])) {
                    $scope['product'] = $scopeResult['productkey'];
                }
                if (isset($scopeResult['session'])) {
                    $scope['event'] = $scopeResult['session'];
                }
            }
        }
    }

    try {
        if (function_exists($action)) {
            // Explicitly cast $action as a string to reassure the debugger.
            $action = (string)$action;
            call_user_func($action);
        }
        else {
            $response = [
                'status' => [
                    'code' => 400
                ],
                'error'  => [
                    'message' => "HTTP `{$method}` not supported for object `{$object}`."
                ],
                'trace'  => [
                    $database
                ]
            ];
            throw new BadRequestException($response);
        }
    } catch (Exception $e) {
        sendResponse($e);
    }
}


/**
 * Dispatch function for GET /search
 *
 * Validates API Key permissions and validates search criteria. Only accepts
 * whitelisted fields and uses query parameters to fight SQL injection. Only
 * returns results within scope of API key. Allows user to specify which
 * columns to return and order of return.
 *
 * @todo Allow user to set start/end limit.
 *
 * @throws BadRequestException
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws WhatTheHeckIsThisException
 */
function api_SEARCH_GET_dispatch() {
    global $path;

    if (count($path) != 1) {
        throw new BadRequestException([ $path ]);
    }

    if (!getPermission("LIST", getCurrentScope())) {
        $response = [
            'status' => [ 'code' => 401 ],
            'error'  => [ 'message' => 'Underprivileged API Key.' ],
            'trace'  => getCurrentScope()
        ];
        throw new BadRequestException($response);
    }

    global $requestQuery;
    global $database;

    switch (count($path)) {
        /** @noinspection PhpMissingBreakStatementInspection */
        case 1:
            $criteria = $path[0];
            break;
        case 0:
            break;
        default:
            $response = [
                'status' => [
                    'code' => 400
                ],
                'error'  => [
                    'message' => 'What are you doing?'
                ],
                'trace'  => [
                    $path
                ]
            ];
            throw new BadRequestException($response);
            break;
    }


    /** @noinspection PhpUndefinedVariableInspection */
    if (strpos($criteria, ":") === False) {
        $response = [
            'status' => [ 'code' => 401 ],
            'error'  => [ 'message' => 'Invalid Search Request' ]
        ];
        throw new BadRequestException($response);
    }

    list($criteria, $value) = explode(':', $criteria);

    $validCriteria = [
        'phone' => [
            'filter'  => FILTER_VALIDATE_REGEXP,
            'options' => [
                'options' => [
                    'regexp' => "/^[0-9]+$/"
                ]
            ],
            'sqlField' => 'phonenumber',
            'sqlType'  => 'i'
        ],
        'dialback' => [
            'filter'  => FILTER_VALIDATE_REGEXP,
            'options' => [
                'options' => [
                    'regexp' => "/^[0-9]+$/"
                ]
            ],
            'sqlField' => 'dialbacknumber',
            'sqlType'  => 's'
        ],
        'email' => [
            'filter'   => FILTER_VALIDATE_EMAIL,
            'sqlField' => 'emailaddress',
            'sqlType'  => 's'
        ]
    ];

    if (!key_exists($criteria, $validCriteria)) {
        $response = [
            'status' => [ 'code' => 401 ],
            'error'  => [ 'message' => 'Invalid Search Request' ]
        ];
        throw new BadRequestException($response);
    }

    $filter  = $validCriteria[ $criteria ]['filter'];
    $options = isset($validCriteria[ $criteria ]['options']) ? $validCriteria[ $criteria ]['options'] : [ ];

    if (!filter_var($value, $filter, $options)) {
        throw new BadRequestException([ "Criteria `{$criteria}`: Invalid value." ]);
    }

    $eventColumns = [
        'session',
        'created'
    ];

    $columnsToSelect = [ ];
    $orderByColumns  = [ ];

    if (count($requestQuery) > 0) {

        // Did they provide Query parameters?
        if (key_exists('select', $requestQuery)) {
            // We've been given specific columns to Select.
            $columns = explode(',', $requestQuery['select'] . ',');

            // Only include valid columns.
            $columnsToSelect = array_intersect($columns, $eventColumns);
        }

        if (key_exists('order', $requestQuery)) {
            // We've been given a specific order.
            $columns = explode(',', $requestQuery['order'] . ',');

            foreach ($columns as $column) {
                if (strlen($column) == 0) {
                    continue;
                }

                // Did they give us a column name only, or a column name and direction?
                if (strstr($column, ' ') !== FALSE) {
                    list($columnName, $direction) = explode(' ', $column);
                }
                else {
                    $columnName = $column;
                    $direction  = '';
                }

                // Is it a valid column? Drop it if not.
                if (in_array($eventColumns, $columnName)) {
                    switch ($direction) {
                        case 'ASC':
                        case 'DESC':
                            // These are acceptable Order directions.
                            break;
                        default:
                            $direction = 'ASC';
                            break;
                    }
                    $orderByColumns[] = $columnName . " " . $direction;
                }
                else {
                    // Intentionally dropping this invalid entry.
                }
            }
        }
    }

    if (count($columnsToSelect) == 0) {
        $columnsToSelect = $eventColumns;
    }
    $columnsInQuery = join(",\n            ", $columnsToSelect);

    $orderBy = '';
    if (count($orderByColumns) > 0) {
        $orderBy = "ORDER BY\n            ";
        $orderBy = $orderBy . join(",\n            ", $orderByColumns);
    }

    $scope = getCurrentScope();

    $whereCriteria = [ ];

    // Yes, we're intentionally replacing the criteria with a more specific one if applicable.
    if ($scope['product'] != "*") {
        $whereCriteria[] = "segmentkey IN (SELECT segmentkey FROM tbl__segments WHERE productkey=" . $scope['product'] . ")";
    }
    if ($scope['segment'] != "*") {
        $whereCriteria[] = "segmentkey=" . $scope['segment'];
    }
    if ($scope['event'] != "*") {
        $whereCriteria[] = "session='" . $scope['event'] . "'";
    }

    $whereCriteria[] = $validCriteria[ $criteria ]['sqlField'] . '=?';

    // By default, select nothing.
    if (count($whereCriteria) == 0) {
        $whereClause = 'FALSE';
    }
    else {
        $whereClause = join("\n        AND ", $whereCriteria);
    }

    // @todo Un-hardcode these.
    $begin = 0;
    $end   = 10;

    $sqlQuery = <<<EOF

        SELECT
            {$columnsInQuery}
        FROM
            tbl__events
        WHERE
            {$whereClause}
        {$orderBy}
        LIMIT
            {$begin}, {$end}
EOF;

    try {
        $rows = $database->select($sqlQuery, [ [ $validCriteria[ $criteria ]['sqlType'] => $value ] ]);
    } catch (DatabaseNothingSelectedException $e) {
        // No rows is OK. Eat exception.
        $rows = [ ];
    }

    $response = [
        'status' => [
            'code'    => 200,
            'message' => 'OK'
        ],
        'data'   => [
            'count' => count($rows),
            'rows'  => $rows
        ],
    ];
    sendResponse($response);
}

/**
 * Dispatch function for GET /events
 *
 * Determines what the user is asking for -- Event info, attachment info --
 * and returns it.  Attachments are returned directly.  If no Event ID is
 * included in the call, lists events user has access to see with API key.
 *
 * @todo Use api_EVENTS_GET_ID to return event information when scope is
 *        Session-only.
 *
 * @throws BadRequestException
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws WhatTheHeckIsThisException
 */
function api_EVENTS_GET_dispatch() {
    global $path;
    global $requestQuery;
    global $database;

    switch (count($path)) {
        /** @noinspection PhpMissingBreakStatementInspection */
        case 3:
            $id2 = $path[2];
        /** @noinspection PhpMissingBreakStatementInspection */
        case 2:
            $object2 = $path[1];
        case 1:
            $session = $path[0];
            break;
        case 0:
            break;
        default:
            $response = [
                'status' => [
                    'code' => 400
                ],
                'error'  => [
                    'message' => 'What are you doing?'
                ],
                'trace'  => [
                    $path
                ]
            ];
            throw new BadRequestException($response);
            break;
    }

    $funcCall = str_replace("_dispatch", "", __FUNCTION__);
    if (isset($session) && strlen($session) > 0) {
        $funcCall  = $funcCall . '_ID';
        $parameter = $session;
        if (isset($object2) && strlen($object2) > 0) {
            $funcCall = $funcCall . '_' . strtoupper($object2);
        }
        if (isset($id2) && strlen($id2) > 0) {
            $funcCall = $funcCall . '_ID';
        }
    }

    if ($funcCall != str_replace("_dispatch", "", __FUNCTION__)) {
        if (function_exists($funcCall)) {
            // Explicitly cast $action as a string to reassure the debugger.
            $funcCall = (string)$funcCall;
            if (isset($parameter)) {
                $funcCall($parameter);
            }
            else {
                $funcCall();
            }
        }
        else {
            $response = [
                'status' => [ 'code' => 400 ],
                'error'  => [ 'message' => 'Not supported:' . $funcCall ]
            ];
            throw new BadRequestException($response);
        }
    }

    if (!getPermission("LIST", getCurrentScope())) {
        $response = [
            'status' => [ 'code' => 401 ],
            'error'  => [ 'message' => 'Underprivileged API Key.' ]
        ];
        throw new BadRequestException($response);
    }

    $eventColumns = [
        'session',
        'phonenumber',
        'emailaddress',
        'latitude',
        'longitude',
        'postal_code',
        'dialbacknumber',
        'state'
    ];

    $columnsToSelect = [ ];
    $orderByColumns  = [ ];

    if (count($requestQuery) > 0) {

        // Did they provide Query parameters?
        if (key_exists('select', $requestQuery)) {
            // We've been given specific columns to Select.
            $columns = explode(',', $requestQuery['select'] . ',');

            // Only include valid columns.
            $columnsToSelect = array_intersect($columns, $eventColumns);
        }

        if (key_exists('order', $requestQuery)) {
            // We've been given a specific order.
            $columns = explode(',', $requestQuery['order'] . ',');

            foreach ($columns as $column) {
                if (strlen($column) == 0) {
                    continue;
                }

                // Did they give us a column name only, or a column name and direction?
                if (strstr($column, ' ') !== FALSE) {
                    list($columnName, $direction) = explode(' ', $column);
                }
                else {
                    $columnName = $column;
                    $direction  = '';
                }

                // Is it a valid column? Drop it if not.
                if (in_array($columnName, $eventColumns)) {
                    switch ($direction) {
                        case 'ASC':
                        case 'DESC':
                            // These are acceptable Order directions.
                            break;
                        default:
                            $direction = 'ASC';
                            break;
                    }
                    $orderByColumns[] = $columnName . " " . $direction;
                }
                else {
                    // Intentionally dropping this invalid entry.
                }
            }
        }
    }

    if (count($columnsToSelect) == 0) {
        $columnsToSelect = $eventColumns;
    }
    $columnsInQuery = join(",\n            ", $columnsToSelect);

    $orderBy = '';
    if (count($orderByColumns) > 0) {
        $orderBy = "ORDER BY\n            ";
        $orderBy = $orderBy . join(",\n            ", $orderByColumns);
    }

    $scope = getCurrentScope();

    // By default, select nothing.
    $whereClause = 'FALSE';

    // Yes, we're intentionally replacing the criteria with a more specific one if applicable.
    if ($scope['product'] != "*") {
        $whereClause = "segmentkey IN (SELECT segmentkey FROM tbl__segments WHERE productkey=" . $scope['product'] . ")";
    }
    if ($scope['segment'] != "*") {
        $whereClause = "segmentkey=" . $scope['segment'];
    }
    if ($scope['event'] != "*") {
        $whereClause = "session='" . $scope['event'] . "'";
    }

    $begin = 0;
    $end   = 10;

    $sqlQuery = <<<EOF
            
        SELECT
            {$columnsInQuery}
        FROM
            tbl__events
        WHERE
            {$whereClause}
        {$orderBy}
        LIMIT
            {$begin}, {$end}
EOF;

    try {
        $rows = $database->select($sqlQuery);
    } catch (DatabaseNothingSelectedException $e) {
        // No rows is OK. Eat exception.
        $rows = [ ];
    }

    $response = [
        'status' => [
            'code'    => 200,
            'message' => 'OK'
        ],
        'data'   => [
            'count' => count($rows),
            'rows'  => $rows
        ],
    ];
    sendResponse($response);
}

/**
 * Handler function for GET /events/:SESSION
 *
 * Returns event information for given Session including attachment count and
 * IDs.
 *
 * @throws BadRequestException
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws WhatTheHeckIsThisException
 */
function api_EVENTS_GET_ID() {
    global $path;

    if (!getPermission("LIST", getCurrentScope())) {
        $response = [
            'status' => [ 'code' => 401 ],
            'error'  => [ 'message' => 'Underprivileged API Key.' ],
            'trace'  => getCurrentScope()
        ];
        throw new BadRequestException($response);
    }

    global $database;

    $session = null;

    switch (count($path)) {
        /** @noinspection PhpMissingBreakStatementInspection */
        case 1:
            $session = $path[0];
            break;
        case 0:
            break;
        default:
            $response = [
                'status' => [
                    'code' => 400
                ],
                'error'  => [
                    'message' => 'What are you doing?'
                ],
                'trace'  => [
                    $path
                ]
            ];
            throw new BadRequestException($response);
            break;
    }

    $sqlQuery = <<<EOF

        SELECT
            eventkey,
            created,
            session,
            phonenumber,
            emailaddress,
            latitude,
            longitude,
            postal_code,
            dialbacknumber,
            state
        FROM
            tbl__events
        WHERE
            session=?
        LIMIT
            1
            
EOF;

    try {
        $rows = $database->select($sqlQuery, [ [ 's' => $session ] ]);
    } catch (DatabaseNothingSelectedException $e) {
        // No rows is OK. Eat exception.
        $rows = [ ];
    }

    if (count($rows) > 0) {
        $sqlQuery = <<<EOF
    
            SELECT
                filekey,
                filename
            FROM
                tbl__files
            WHERE
                eventkey=?
            LIMIT
                1
                
EOF;

        try {
            $files = $database->select($sqlQuery, [ [ 'i' => $rows[0]['eventkey'] ] ]);
        } catch (DatabaseNothingSelectedException $e) {
            // No rows is OK. Eat exception.
            $files = [ ];
        }

        $rows[0]['files'] = $files;
    }

    unset($rows[0]['eventkey']);

    $response = [
        'status' => [
            'code'    => 200,
            'message' => 'OK'
        ],
        'data'   => [
            'count' => count($rows),
            'rows'  => $rows
        ],
    ];
    sendResponse($response);
}

/**
 * Handler function for GET /events/:SESSION/attachments/:ID
 *
 * Loads the specified attachment and prompts the browser to download it.
 *
 * @throws ApiKeyNotPrivilegedException
 * @throws BadRequestException
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws WhatTheHeckIsThisException
 */
function api_EVENTS_GET_ID_ATTACHMENTS_ID() {
    global $path;
    global $database;
    $session = $path[0];

    if (!getPermission("LIST", getScopeByEventSession($session))) {
        throw new ApiKeyNotPrivilegedException();
    }

    $sqlQuery = <<<EOF

        SELECT
            filename,
            filepath
        FROM
            tbl__files
        INNER JOIN
            tbl__events
        ON
            tbl__events.eventkey=tbl__files.eventkey
        WHERE
            tbl__events.session='{$session}'
        AND tbl__files.filekey={$path[2]}
EOF;

    try {
        $file = $database->select($sqlQuery, [ ]); //  [ [ 's' => $session ], [ 'i' => $path[2] ] ]
    } catch (DatabaseNothingSelectedException $e) {
        $response = [
            'status' => [
                'code'    => 404,
                'message' => 'Not Found'
            ],
            'error'  => [
                'message' => 'That attachment is not found.',
            ],
        ];
        throw new BadRequestException($response);
    }

    if (!file_exists($file[0]['filepath'])) {
        $response = [
            'status' => [
                'code'    => 503,
                'message' => 'Temporarily Not Available'
            ],
            'error'  => [
                'message' => 'The requested attachment cannot be provided right now.',
            ],
        ];
        throw new BadRequestException($response);
    }
    else {
        //        header('X-Sendfile: ' . $file[0]['filepath']);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($file[0]['filename']));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file[0]['filepath']));

        readfile($file[0]['filepath']);
        exit;

    }


}

/**
 * Dispatch function for POST /events
 *
 * Determines what the user is requesting -- for example, to add an
 * attachment or to create a new session -- and dispatches or handles.
 *
 * If a new event is requested, takes user input and turns it into an event,
 * including web call to Dialback provider, Event insertion, and API key
 * creation.
 *
 * @todo Why does event creation need to be JSON? Seemed like a good idea at
 *        the time. Move to just HTTP POST fields.
 *
 * @todo Assumes web call for Dialback number succeeded. Add failure handling.
 *
 * @throws BadRequestException
 */
function api_EVENTS_POST_dispatch() {
    global $database;
    global $path;
    global $apiKey;

    switch (count($path)) {
        /** @noinspection PhpMissingBreakStatementInspection */
        case 3:
            if ($path[2] != "") {
                $response = [
                    'status' => [
                        'code'    => 400,
                        'message' => 'Bad Request'
                    ],
                    'error'  => [
                        'message' => 'Invalid Request Path',
                    ],
                ];
                throw new BadRequestException($response);
            }

        /** @noinspection PhpMissingBreakStatementInspection */
        case 2:
            $object2 = $path[1];
        case 1:
            $session = $path[0];
            break;
        case 0:
            break;
        default:
            $response = [
                'status' => [
                    'code'    => 400,
                    'message' => 'Bad Request'
                ],
                'error'  => [
                    'message' => 'Invalid Request Path',
                ],
            ];
            throw new BadRequestException($response);
            break;
    }


    $funcCall = str_replace("_dispatch", "", __FUNCTION__);
    if (isset($session) && strlen($session) > 0) {
        $funcCall  = $funcCall . '_ID';
        $parameter = $session;
        if (isset($object2) && strlen($object2) > 0) {
            $funcCall = $funcCall . '_' . strtoupper($object2);
        }
    }

    if ($funcCall != str_replace("_dispatch", "", __FUNCTION__)) {
        if (function_exists($funcCall)) {
            // Explicitly cast $action as a string to reassure the debugger.
            $funcCall = (string)$funcCall;
            if (isset($parameter)) {
                $funcCall($parameter);
            }
            else {
                $funcCall();
            }
        }
        else {
            $response = [
                'status' => [
                    'code'    => 400,
                    'message' => 'Bad Request'
                ],
                'error'  => [
                    'message' => 'Unsupported API Request.',
                ],
            ];
            throw new BadRequestException($response);
        }
    }
    else {
        try {
            if (!$jsonRequest = json_decode($_POST['request'], true)) {
                throw new InvalidJsonException([ $jsonRequest ]);
            }

            $requiredFields = [
                'segment'      => [
                    'filter' => FILTER_VALIDATE_INT,
                ],
                'phoneNumber'  => [
                    'filter'  => FILTER_VALIDATE_REGEXP,
                    'options' => [
                        'options' => [
                            'regexp' => "/^\+? ?[0-9 ]+$/"
                        ]
                    ]
                ],
                'emailAddress' => [
                    'filter' => FILTER_VALIDATE_EMAIL,
                ],
                'state'        => [
                    'filter'  => FILTER_VALIDATE_REGEXP,
                    'options' => [
                        'options' => [
                            'regexp' => "/^[A-Za-z ]{4,50}$/"
                        ]
                    ]
                ],
                'latitude'     => [
                    'filter' => FILTER_VALIDATE_FLOAT,
                ],
                'longitude'    => [
                    'filter' => FILTER_VALIDATE_FLOAT,
                ]
            ];

            foreach ($requiredFields as $key => $parameters) {
                if (!isset($jsonRequest[ $key ])) {
                    throw new BadRequestException([ "Required parameter `{$key}` is missing." ]);
                }

                $value   = $jsonRequest[ $key ];
                $filter  = $parameters['filter'];
                $options = isset($parameters['options']) ? $parameters['options'] : [ ];

                if (!filter_var($value, $filter, $options)) {
                    throw new BadRequestException([ "Parameter `{$key}`: Invalid value." ]);
                }
            }

            $sqlQuery = <<<EOF
            
                SELECT
                    productphoneserver
                FROM
                    tbl__products
                INNER JOIN
                    tbl__segments
                ON
                    tbl__products.productkey=tbl__segments.productkey
                WHERE
                    tbl__segments.segmentkey=?
                
EOF;

            $dialbackQuery = $database->select($sqlQuery, [
                    [ 'i' => $jsonRequest['segment'] ]
                ]
            );

            $data = [
                'emailaddress' => $jsonRequest['emailAddress'],
                'phonenumber'  => $jsonRequest['phoneNumber'],
                'latitude'     => $jsonRequest['latitude'],
                'logitude'     => $jsonRequest['longitude'],
                'state'        => $jsonRequest['state']
            ];

            // use key 'http' even if you send the request to https://...
            $options = array(
                'http' => array(
                    'header'  => "Content-type: 
                    application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($data)
                )
            );

            $dialbackNumber = file_get_contents
            ($dialbackQuery[0]['productphoneserver'], false,
                stream_context_create
                ($options));

            if ($dialbackNumber === FALSE) { /* Handle error */
                throw new NoDialbackNumberProvidedException([ ]);
            }

            $sqlQuery = <<<EOF
            
                INSERT INTO
                    tbl__events
                    (
                        session,
                        segmentkey,
                        phonenumber,
                        emailaddress,
                        latitude,
                        longitude,
                        state,
                        dialbacknumber
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        
EOF;

            $sessionId  = null;
            $eventQuery = null;
            $eventAdded = false;
            $attempts   = 1;
            $i          = 0;
            $lastError  = null;

            do {
                try {
                    $sessionId = generateSessionId();

                    $eventQuery = $database->insert($sqlQuery, [
                            [ 's' => $sessionId ],
                            [ 'i' => $jsonRequest['segment'] ],
                            [ 's' => $jsonRequest['phoneNumber'] ],
                            [ 's' => $jsonRequest['emailAddress'] ],
                            [ 'd' => $jsonRequest['latitude'] ],
                            [ 'd' => $jsonRequest['longitude'] ],
                            [ 's' => $jsonRequest['state'] ],
                            [ 's' => $dialbackNumber ]
                        ]
                    );

                    $eventAdded = true;
                } catch (DatabaseInsertQueryFailedException $e) {
                    $lastError = print_r($e, true);
                }

                $i++;

            } while (!$eventAdded AND $i <= $attempts);

            if (!$eventAdded) {
                throw new EventNotAddedException([ $lastError ]);
            }


            $sqlQuery = <<<EOF
            
                INSERT INTO
                    tbl__apikeys
                (
                    expiration,
                    scope,
                    ALLOW_RENEW,
                    ALLOW_UPLOAD,
                    ALLOW_LIST,
                    apikey,
                    scopekey
                )
                VALUES
                (
                    DATE_ADD(NOW(), INTERVAL 1 HOUR),
                    'EVENT',
                    1,
                    1,
                    1,
                    ?, ?
                )
                
EOF;

            $apiKey      = null;
            $scopeKey    = (int)$eventQuery->insert_id;
            $apiKeyAdded = false;
            $attempts    = 1;
            $i           = 0;

            $apiKeyQuery = null;
            do {
                try {
                    $apiKey = generateApiKey($sessionId);

                    $apiKeyQuery = $database->insert($sqlQuery, [
                            [ 's' => $apiKey ],
                            [ 'i' => $scopeKey ]
                        ]
                    );

                    $apiKeyAdded = true;
                } catch (DatabaseInsertQueryFailedException $e) {
                    $lastError = print_r($e, true);
                }

                $i++;

            } while (!$apiKeyAdded AND $i <= $attempts);

            if (!$apiKeyAdded) {
                throw new ApiKeyNotAddedException([ $lastError ]);
            }

            $eventQuery->close();
            $apiKeyQuery->close();


            $response = [
                'data'   => [
                    'session' => $sessionId,
                    'dial'    => $dialbackNumber,
                    'apiKey'  => $apiKey
                ],
                'status' => [
                    'code' => 201
                ]
            ];
            sendResponse($response);
        } catch (Exception $e) {
            sendResponse($e);
        }
    }

}

/**
 *  Handler function for GET /events/:SESSION/attachments/:ID
 *
 * Receives attachment(s), saves to disk, and adds to database. Renews API
 * key when file received.
 *
 * @todo Move the upload location outside the webroot.
 *
 * @param string $id Session ID provided by the dispatcher.
 *
 * @throws ApiKeyNotPrivilegedException
 * @throws DatabaseInsertQueryFailedException
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseNothingSelectedException
 * @throws DatabaseRowNotInsertedException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws InvalidIdentifierException
 * @throws NoFilesProvidedException
 * @throws WhatTheHeckIsThisException
 */
function api_EVENTS_POST_ID_ATTACHMENTS($id) {
    global $database;

    if (!isset($id) || strlen($id) != 8) {
        throw new InvalidIdentifierException();
    }

    if (!getPermission("UPLOAD", getScopeByEventSession($id))) {
        throw new ApiKeyNotPrivilegedException();
    }

    if (empty($_FILES)) {
        throw new NoFilesProvidedException();
    }

    $status = [
        'data'  => [ ],
        'error' => [
            'count' => 0
        ]
    ];

    $sqlQuery = <<<EOF

        SELECT
            eventkey
        FROM
            tbl__events
        WHERE
            session=?
        LIMIT 1

EOF;

    $sessionkey = $database->select($sqlQuery, [ [ "s" => $id ] ])[0]['eventkey'];

    $pattern = '/' . str_replace('\\', '\\\\', $_SERVER['DOCUMENT_ROOT']) . '\/(.+)(?=\\\\.+\.php$)/';
    preg_match($pattern, $_SERVER['SCRIPT_FILENAME'], $matches);

    // $baseDir = $matches[1];
    $baseDir = __DIR__;

    $i = 0;
    foreach ($_FILES as $file) {
        // $status['data']['files'][ $i ]['trace'] = $file;

        if ($file['error'] > 0) {
            $status['data']['files'][ $i ]['error'] = $file['error'];
            $status['error']['count']++;
        }
        else {
            $destination = $baseDir . DIRECTORY_SEPARATOR . 'up' . DIRECTORY_SEPARATOR . $id . '_' . $file['name'];
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                $status['data']['files'][ $i ]['error'] = "Failed to move 
                `{$file['tmp_name']}` to permanent storage.";
                $status['error']['count']++;
            }
            else {
                $sqlQuery = <<<EOF

                    INSERT INTO
                        tbl__files
                    (
                        eventkey,
                        filename,
                        filepath
                    )
                    VALUES
                    (?, ?, ?)

EOF;
                $database->insert($sqlQuery, [
                    [ 'i' => $sessionkey ],
                    [ 's' => $file['name'] ],
                    [ 's' => $destination ]
                ]);
                //chmod($destination, 0644);
                $status['data']['files'][ $i ]['name'] = $file['name'];
            }
        }
        $i++;
    }

    if ($status['error']['count'] == 0) {
        // Everything Worked
        $status['status']['code'] = 200;
    }
    elseif ($status['error']['count'] == count($status['data']['files'])) {
        // Everything failed.
        $status['status']['code'] = 500;
    }
    else {
        // Mixed Results
        $status['status']['code'] = 207;
    }

    try {
        if (getPermission("RENEW", getScopeByEventSession($id))) {
            $sqlQuery = <<<EOF

                UPDATE
                    tbl__apikeys
                SET
                    expiration = DATE_ADD(NOW(), INTERVAL 1 HOUR),
                    last_renewal = NOW()
                WHERE
                    scope='EVENT'
                AND scopekey=(SELECT eventkey FROM tbl__events WHERE session=?)
                AND is_expired=0

EOF;

            try {
                //                $eventQuery =
                $database->update($sqlQuery, [
                        [ 's' => $id ]
                    ]
                );
            } catch (DatabaseInsertQueryFailedException $e) {
                //$lastError = print_r($e, true);
            }

        }
    } catch (LeagleEyeException $e) {
        $status['error']['apiKeyRenewalError'] = $e->getResponse();
    }
    sendResponse($status);
}

/**
 * Generates Session ID.
 *
 * Uses collection of unambiguous letters and numbers to generate unique IDs
 * for sessions.
 *
 * @return string The generated ID.
 */
function generateSessionId() {
    return strtoupper(substr(str_shuffle(str_repeat("aeufhlmr145670", 8)), 0, 8));
}

/**
 * Generates API Key.
 *
 * Returns SHA-1 of Session ID, Current Time, and random number.
 *
 * @param string $sessionId Session ID associated with API key.
 *
 * @return string Generated API key.
 */
function generateApiKey($sessionId) {
    return sha1($sessionId . microtime(true) . mt_rand(10000, 90000));
}

/**
 * Sends response to browser.
 *
 * @todo Make this handle XML and maybe even SOAP because why not.
 *
 * @param string $response The unencoded response to send
 * @param bool $exitAfter Whether or not to exit after sending (default: true)
 */
function sendResponse($response, $exitAfter = true) {

    if ($response instanceof LeagleEyeException) {
        $base = $response->getResponse();
    }
    else {
        $base = $response;
    }

    if (!isset($base['status'])) {
        $base['status'] = [ 'code' => null, 'message' => '' ];
    }

    //$status = &$base['status'];

    if ($base['status']['message'] == '') {
        switch ($base['status']['code']) {
            case 200:
                $base['status']['message'] = "OK";
                break;
            case 201:
                $base['status']['message'] = "Created";
                break;
            case 400:
                $base['status']['message'] = "Bad Request";
                break;
            case 500:
                $base['status']['message'] = "Internal Server Error";
                break;
            case null:
                $base['status']['code']    = 500;
                $base['status']['message'] = "No Status Provided";
                break;
        }
    }

    header("HTTP/1.1 {$base['status']['code']} {$base['status']['message']}");
    header('Content-type: application/json');
    header('Access-Control-Allow-Origin: *');
    print json_encode($base);
    
    if ($exitAfter) {
        exit();
    }
}

/**
 * Checks if the scope of the current API key is compatibile with the
 * requested operatiion.
 *
 * @param string $action The action to do. 1:1 with ALLOW_x in database.
 * @param array $compare The scope when requesting the action.
 *
 * @return bool If permission was granted
 */
function getPermission($action, $compare = array()) {
    global $scope;
    global $permissions;

    if (count($scope) == 0) {
        return false;
    }

    foreach (array( "event", "product", "segment" ) as $attribute) {
        if (!fnmatch($scope[ $attribute ], $compare[ $attribute ])) {
            return false;
        }
    }

    $key = strtoupper("allow_" . $action);
    if (!isset($permissions[ $key ])) {
        return false;
    }
    else {
        return ($permissions[ $key ] == 1);
    }
}

/**
 * Returns the scope associated with an Event.
 *
 * @param string $event
 *
 * @return array An array with the scope associated to that event.
 *
 * @throws DatabaseInvalidQueryTypeException
 * @throws DatabaseNothingSelectedException
 * @throws DatabaseSelectQueryFailedException
 * @throws DatabaseStatementNotPreparedException
 * @throws WhatTheHeckIsThisException
 */
function getScopeByEventSession($event) {
    global $database;

    $sqlQuery = <<<EOF
    SELECT
        session AS event,
        tbl__events.segmentkey AS segment,
        tbl__segments.productkey AS product
    FROM
        tbl__events
    LEFT JOIN
        tbl__segments
    ON
        tbl__events.segmentkey=tbl__segments.segmentkey
    WHERE
        session=(?)
    LIMIT 1
EOF;

    // By virtue of LIMIT 1 this can only ever have a single row, so send back the zeroth element.
    return $database->select($sqlQuery, [ [ 's' => $event ] ])[0];
}

/**
 * Returns the current scope by API key.
 *
 * @return array
 */
function getCurrentScope() {
    global $scope;

    return $scope;
}                