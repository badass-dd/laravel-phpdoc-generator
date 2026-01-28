<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Controller Paths
    |--------------------------------------------------------------------------
    |
    | Directory da scansionare per trovare i controller.
    | Puoi aggiungere percorsi personalizzati per progetti con strutture non standard.
    |
    */
    'controller_paths' => [
        app_path('Http/Controllers'),
        app_path('Http/Controllers/Api'),
        app_path('Http/Controllers/Admin'),
        app_path('Http/Controllers/V1'),
        app_path('Http/Controllers/V2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Methods
    |--------------------------------------------------------------------------
    |
    | Metodi che NON devono essere analizzati né documentati.
    | I metodi magici di PHP e i metodi utility di Laravel sono esclusi di default.
    |
    */
    'exclude_methods' => [
        // Magic methods
        '__construct',
        '__destruct',
        '__call',
        '__callStatic',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__sleep',
        '__wakeup',
        '__toString',
        '__invoke',
        '__set_state',
        '__clone',
        '__debugInfo',

        // Laravel utility methods
        'middleware',
        'validator',
        'validate',
        'authorize',
        'validateWithBag',
        'withValidator',

        // Common utility methods
        'boot',
        'bootTraits',
        'register',
        'getMiddleware',
        'callAction',

        // Custom exclusions (aggiungi i tuoi)
        // 'myUtilityMethod',
    ],

    /*
    |--------------------------------------------------------------------------
    | Complexity Threshold
    |--------------------------------------------------------------------------
    |
    | Soglia minima di complessità ciclomatica per documentare un metodo.
    | Metodi con complessità inferiore saranno ignorati (a meno che non si usi --force).
    | Range: 1-30 (1 = tutti i metodi, 5 = solo metodi moderatamente complessi)
    |
    */
    'complexity_threshold' => 3,

    /*
    |--------------------------------------------------------------------------
    | Include Simple Methods
    |--------------------------------------------------------------------------
    |
    | Se true, include metodi semplici (getter/setter di base) nella documentazione.
    | Se false, richiede l'opzione --force per includerli.
    |
    */
    'include_simple_methods' => false,

    /*
    |--------------------------------------------------------------------------
    | Documentation Features
    |--------------------------------------------------------------------------
    |
    | Controlla quali caratteristiche includere nella documentazione generata.
    | Disattiva le feature che non ti servono per documentazione più snella.
    |
    */
    'features' => [
        'implementation_notes' => true,
        'authorization_errors' => true,
        'rate_limit_info' => true,
        'exception_handling' => true,
        'cache_info' => true,
        'transaction_info' => true,
        'pagination_info' => true,
        'validation_examples' => true,
        'response_examples' => true,
        'success_messages' => true,
        'error_messages' => true,
        'http_codes_explained' => true,
        'authentication_info' => true,
        'deprecation_notices' => false,
        'versioning_info' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Examples
    |--------------------------------------------------------------------------
    |
    | Configurazione per la generazione degli esempi di risposta.
    | Gli esempi realistici aiutano gli sviluppatori a capire la struttura dei dati.
    |
    */
    'response_examples' => [
        'generate_for_success' => true,
        'generate_for_errors' => true,
        'max_examples_per_status' => 3,
        'use_faker' => true,
        'realistic_examples' => true,
        'include_relationships' => true,
        'include_timestamps' => true,
        'max_nesting_level' => 3,
        'example_items_count' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Analysis
    |--------------------------------------------------------------------------
    |
    | Configurazione per l'analisi dei modelli Eloquent.
    | L'analisi dei modelli permette di generare esempi realistici basati sulla struttura DB.
    |
    */
    'model_analysis' => [
        'detect_relations' => true,
        'detect_casts' => true,
        'detect_fillable' => true,
        'detect_hidden' => true,
        'detect_appends' => true,
        'analyze_database' => true,
        'follow_relations_depth' => 2,
        'cache_model_info' => true,
        'cache_ttl' => 3600, // secondi
        'skip_models' => [
            // 'App\Models\PasswordReset',
            // 'App\Models\FailedJob',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules Analysis
    |--------------------------------------------------------------------------
    |
    | Configurazione per l'analisi delle regole di validazione.
    | Estrae automaticamente le regole dai FormRequest e le converte in documentazione.
    |
    */
    'validation' => [
        'extract_from_formrequests' => true,
        'infer_types_from_rules' => true,
        'generate_examples' => true,
        'include_constraints' => true,
        'parse_custom_rules' => true,
        'include_messages' => false,
        'include_attributes' => false,
        'cache_rules' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | AST Analysis
    |--------------------------------------------------------------------------
    |
    | Configurazione per il parser AST (Abstract Syntax Tree).
    | L'AST è il cuore del sistema e permette di analizzare il codice in modo preciso.
    |
    */
    'ast_analysis' => [
        'max_depth' => 15,
        'timeout' => 60, // secondi
        'memory_limit' => '512M',
        'cache_enabled' => true,
        'cache_ttl' => 7200, // secondi (2 ore)
        'cache_directory' => storage_path('framework/cache/lazydocs'),
        'skip_large_files' => true,
        'max_file_size' => 1024 * 100, // 100KB
        'parallel_processing' => false,
        'worker_count' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Settings
    |--------------------------------------------------------------------------
    |
    | Configurazione per l'output della documentazione generata.
    | Supporta diversi formati e strategie di merge con documentazione esistente.
    |
    */
    'output' => [
        'format' => 'scribe', // scribe, openapi, markdown
        'format_output' => true,
        'preserve_existing' => true,
        'merge_strategy' => 'smart', // overwrite, merge, smart
        'group_strategy' => 'controller', // controller, namespace, custom
        'default_group' => 'API',
        'custom_groups' => [
            // 'Admin' => ['AdminController', 'UserManagementController'],
            // 'Public' => ['HomeController', 'ContactController'],
        ],
        'line_ending' => PHP_EOL,
        'indent_size' => 4,
        'wrap_length' => 120,
        'generate_toc' => false,
        'include_metadata' => true,
        'metadata' => [
            'generated_at' => true,
            'generator' => 'LazyDocs',
            'version' => '2.0.0',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Examples
    |--------------------------------------------------------------------------
    |
    | Esempi personalizzati per nomi di campo specifici.
    | Sovrascrive la generazione automatica di esempi per questi campi.
    |
    */
    'field_examples' => [
        'email' => 'user@example.com',
        'name' => 'John Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+1-234-567-8900',
        'mobile' => '+1-234-567-8901',
        'address' => '123 Main St, Anytown, USA',
        'street' => '123 Main St',
        'city' => 'New York',
        'state' => 'NY',
        'zip_code' => '10001',
        'postal_code' => '10001',
        'country' => 'United States',
        'country_code' => 'US',
        'date_of_birth' => '1990-01-01',
        'birth_date' => '1990-01-01',
        'website' => 'https://example.com',
        'url' => 'https://example.com',
        'bio' => 'Software developer with 10+ years of experience.',
        'description' => 'Detailed description of the item.',
        'price' => 99.99,
        'amount' => 99.99,
        'cost' => 99.99,
        'total' => 199.98,
        'quantity' => 1,
        'count' => 1,
        'is_active' => true,
        'active' => true,
        'enabled' => true,
        'status' => 'active',
        'type' => 'standard',
        'role' => 'user',
        'permissions' => ['read', 'write'],
        'tags' => ['urgent', 'important'],
        'categories' => ['electronics', 'computers'],
        'sku' => 'SKU-12345',
        'product_code' => 'PROD-001',
        'order_number' => 'ORD-2024-001',
        'invoice_number' => 'INV-2024-001',
        'tax_rate' => 0.21,
        'discount' => 10.00,
        'shipping_cost' => 5.99,
        'weight' => 2.5,
        'dimensions' => ['length' => 10, 'width' => 5, 'height' => 3],
        'rating' => 4.5,
        'reviews_count' => 42,
        'views_count' => 1000,
        'likes_count' => 150,
        'shares_count' => 25,
        'comments_count' => 10,
        'duration' => 120,
        'file_size' => 1024 * 1024, // 1MB
        'mime_type' => 'application/pdf',
        'file_name' => 'document.pdf',
        'image_url' => 'https://example.com/image.jpg',
        'avatar_url' => 'https://example.com/avatar.jpg',
        'cover_url' => 'https://example.com/cover.jpg',
        'latitude' => 40.7128,
        'longitude' => -74.0060,
        'timezone' => 'America/New_York',
        'locale' => 'en_US',
        'currency' => 'USD',
        'language' => 'en',
        'theme' => 'light',
        'settings' => ['notifications' => true, 'newsletter' => false],
        'metadata' => ['source' => 'web', 'campaign' => 'summer2024'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    |
    | Messaggi di errore predefiniti per i vari codici di stato HTTP.
    | Puoi personalizzare i messaggi per adattarli al tono della tua API.
    |
    */
    'error_messages' => [
        400 => [
            'default' => 'Bad Request',
            'validation' => 'The request contains invalid data.',
            'malformed' => 'Malformed request syntax.',
        ],
        401 => [
            'default' => 'Unauthenticated',
            'token' => 'Invalid or expired authentication token.',
            'credentials' => 'Invalid credentials provided.',
        ],
        403 => [
            'default' => 'Forbidden',
            'permission' => 'You do not have permission to perform this action.',
            'ownership' => 'You can only access your own resources.',
        ],
        404 => [
            'default' => 'Not Found',
            'resource' => 'The requested resource does not exist.',
            'route' => 'The requested endpoint was not found.',
        ],
        405 => [
            'default' => 'Method Not Allowed',
        ],
        409 => [
            'default' => 'Conflict',
            'duplicate' => 'A resource with these details already exists.',
            'state' => 'Resource state conflict.',
        ],
        422 => [
            'default' => 'Validation Error',
            'fields' => 'One or more fields failed validation.',
        ],
        429 => [
            'default' => 'Too Many Requests',
            'rate_limit' => 'Rate limit exceeded. Please try again later.',
        ],
        500 => [
            'default' => 'Internal Server Error',
            'server' => 'An unexpected server error occurred.',
        ],
        503 => [
            'default' => 'Service Unavailable',
            'maintenance' => 'The service is temporarily unavailable for maintenance.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Generators
    |--------------------------------------------------------------------------
    |
    | Registra generatori di output personalizzati.
    | Implementa l'interfaccia `DocumentationGeneratorInterface`.
    |
    */
    'generators' => [
        // 'openapi' => \Badass\LazyDocs\Generators\OpenApiGenerator::class,
        // 'markdown' => \Badass\LazyDocs\Generators\MarkdownGenerator::class,
        // 'postman' => \App\Generators\PostmanGenerator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Analyzers
    |--------------------------------------------------------------------------
    |
    | Registra analizzatori personalizzati.
    | Estendi `BaseAnalyzer` per aggiungere nuove analisi.
    |
    */
    'analyzers' => [
        // \App\Analyzers\BusinessLogicAnalyzer::class,
        // \App\Analyzers\SecurityAnalyzer::class,
        // \App\Analyzers\PerformanceAnalyzer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Visitors
    |--------------------------------------------------------------------------
    |
    | Registra visitor AST personalizzati.
    | Estendi `NodeVisitorAbstract` per analisi specifiche.
    |
    */
    'visitors' => [
        // \App\Visitors\CustomPatternVisitor::class,
        // \App\Visitors\SecurityVisitor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Detection
    |--------------------------------------------------------------------------
    |
    | Configurazione per il rilevamento automatico del rate limiting.
    | Analizza middleware, trait e chiamate per identificare limitazioni.
    |
    */
    'rate_limiting' => [
        'detect_middleware' => true,
        'detect_traits' => true,
        'detect_calls' => true,
        'default_max_attempts' => 60,
        'default_decay_minutes' => 1,
        'custom_limits' => [
            // 'login' => ['max_attempts' => 5, 'decay_minutes' => 1],
            // 'api' => ['max_attempts' => 100, 'decay_minutes' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Detection
    |--------------------------------------------------------------------------
    |
    | Configurazione per il rilevamento automatico dei requisiti di autenticazione.
    | Analizza middleware, guard e chiamate di autorizzazione.
    |
    */
    'authentication' => [
        'detect_middleware' => true,
        'detect_guards' => true,
        'detect_policies' => true,
        'default_guard' => 'api',
        'common_middleware' => [
            'auth',
            'auth:api',
            'auth:sanctum',
            'auth.basic',
            'auth.session',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configurazione della cache per migliorare le performance.
    | La cache evita di ri-analizzare codice non modificato.
    |
    */
    'cache' => [
        'enabled' => true,
        'driver' => 'file', // file, redis, array
        'prefix' => 'lazydocs_',
        'ttl' => 3600, // secondi
        'clear_on_generate' => false,
        'warmup' => [
            'enabled' => false,
            'paths' => [app_path('Http/Controllers')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configurazione del logging per debugging e monitoraggio.
    | Utile durante lo sviluppo o per tracciare problemi in produzione.
    |
    */
    'logging' => [
        'enabled' => false,
        'channel' => 'stack',
        'level' => 'info',
        'log_file' => storage_path('logs/lazydocs.log'),
        'log_analysis' => false,
        'log_performance' => false,
        'log_errors' => true,
        'log_warnings' => true,
        'verbose' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    |
    | Configurazioni per ottimizzare le performance su codebase di grandi dimensioni.
    |
    */
    'performance' => [
        'batch_size' => 10,
        'memory_limit' => '512M',
        'time_limit' => 300,
        'skip_large_controllers' => true,
        'large_controller_threshold' => 1000, // linee di codice
        'parallel_processing' => false,
        'worker_timeout' => 30,
        'optimize_memory' => true,
        'clear_cache_between_batches' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Modalità debug per sviluppo e troubleshooting.
    | Attiva solo durante lo sviluppo o per risolvere problemi.
    |
    */
    'debug' => [
        'enabled' => env('LAZYDOCS_DEBUG', false),
        'dump_ast' => false,
        'dump_analysis' => false,
        'show_timings' => false,
        'show_memory' => false,
        'verbose_output' => false,
        'skip_cache' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration Settings
    |--------------------------------------------------------------------------
    |
    | Configurazioni per l'integrazione con altri strumenti e servizi.
    |
    */
    'integrations' => [
        'scribe' => [
            'auto_generate' => false,
            'config_path' => config_path('scribe.php'),
            'after_generate' => 'php artisan scribe:generate',
        ],
        'openapi' => [
            'auto_generate' => false,
            'output_path' => public_path('openapi.yaml'),
        ],
        'git' => [
            'auto_commit' => false,
            'commit_message' => 'docs: auto-generated API documentation',
            'branch' => 'main',
        ],
        'ci_cd' => [
            'fail_on_changes' => false,
            'check_only' => false,
            'output_diff' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Directives
    |--------------------------------------------------------------------------
    |
    | Direttive personalizzate per estendere la sintassi della documentazione.
    | Formato: @nomeDirettiva parametri
    |
    */
    'custom_directives' => [
        // '@internal' => 'For internal use only',
        // '@deprecated' => 'This endpoint is deprecated',
        // '@beta' => 'This endpoint is in beta',
        // '@version' => 'v1.0',
        // '@since' => '2024-01-01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Specific Settings
    |--------------------------------------------------------------------------
    |
    | Configurazioni specifiche per ambiente (local, staging, production).
    | Le impostazioni vengono sovrascritte in base all'ambiente corrente.
    |
    */
    'environments' => [
        'local' => [
            'debug.enabled' => true,
            'cache.enabled' => false,
            'logging.enabled' => true,
            'performance.parallel_processing' => false,
        ],
        'staging' => [
            'debug.enabled' => false,
            'cache.enabled' => true,
            'logging.enabled' => true,
        ],
        'production' => [
            'debug.enabled' => false,
            'cache.enabled' => true,
            'logging.enabled' => false,
            'performance.skip_large_controllers' => true,
        ],
    ],
];
