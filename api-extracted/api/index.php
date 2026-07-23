<?php
/**
 * API Root - Information Page
 */

header('Content-Type: application/json; charset=UTF-8');

$apiInfo = [
    'name' => 'Stock Exchange Database API',
    'version' => '1.0',
    'status' => 'active',
    'description' => 'RESTful API providing read-only access to all database tables',
    'base_url' => '/api/v1/',
    'documentation' => '/api/docs/API_DOCUMENTATION.md',
    'test_interface' => '/api/test.html',
    'endpoints' => [
        [
            'path' => '/api/v1/tables.php',
            'method' => 'GET',
            'description' => 'Get list of all available tables',
            'example' => '/api/v1/tables.php'
        ],
        [
            'path' => '/api/v1/schema.php',
            'method' => 'GET',
            'description' => 'Get structure of a specific table',
            'parameters' => ['table (required)'],
            'example' => '/api/v1/schema.php?table=clients'
        ],
        [
            'path' => '/api/v1/index.php',
            'method' => 'GET',
            'description' => 'Query data from any table',
            'parameters' => [
                'table (required)',
                'id (optional)',
                'search (optional)',
                'page (optional, default: 1)',
                'limit (optional, default: 50, max: 500)',
                'sort_by (optional, default: id)',
                'sort_order (optional, default: ASC)',
                '{column_name} (optional) - filter by column value'
            ],
            'examples' => [
                '/api/v1/index.php?table=clients&limit=10',
                '/api/v1/index.php?table=trades&search=apple',
                '/api/v1/index.php?table=employees&sort_by=name&sort_order=ASC'
            ]
        ]
    ],
    'features' => [
        'Read-only access (GET only)',
        '90 queryable tables',
        'Pagination support',
        'Full-text search',
        'Column filtering',
        'Sorting',
        'JSON responses',
        'Error handling',
        'Request logging'
    ],
    'table_categories' => [
        'accounting' => 18,
        'trading' => 24,
        'clients' => 4,
        'employees' => 17,
        'financial' => 10,
        'system' => 17
    ],
    'total_tables' => 90,
    'response_format' => [
        'success' => 'boolean',
        'status_code' => 'integer',
        'timestamp' => 'string (ISO 8601)',
        'message' => 'string',
        'data' => 'object/array'
    ],
    'quick_start' => [
        '1. Visit /api/test.html for interactive testing',
        '2. Read documentation at /api/docs/API_DOCUMENTATION.md',
        '3. For chatbot integration, see /api/docs/CHATBOT_QUICK_REFERENCE.md',
        '4. Start with /api/v1/tables.php to see available tables'
    ],
    'support' => [
        'Documentation: /api/docs/',
        'Test Interface: /api/test.html',
        'Logs: /logs/api_access.log and /logs/api_errors.log'
    ]
];

echo json_encode($apiInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
