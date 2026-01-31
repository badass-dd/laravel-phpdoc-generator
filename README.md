# 🚀 LazyDocs

**Stop writing PHPDoc manually. Let your code speak for itself.**

LazyDocs is an intelligent PHPDoc generator for Laravel that analyzes your actual code—validation rules, Eloquent operations, response patterns—and generates accurate Scribe-compatible documentation automatically.

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## ✨ Why LazyDocs?

| Feature | Traditional Approach | LazyDocs |
|---------|---------------------|----------|
| `@bodyParam` | Manual typing | Auto-inferred from FormRequest rules |
| `@response` | Copy-paste JSON | Generated from actual return statements |
| Types & Examples | Guesswork | Derived from validation (`exists:users,id` → `integer`, `date` → `2025-01-15`) |
| Destroy methods | Often wrong `@response 200` | Detects `->delete()` → `@response 204` |

---

## 📦 Installation

```bash
composer require badass-dd/lazy-docs --dev
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=lazydocs-config
```

---

## 🎯 Quick Start

```bash
# Generate docs for a single controller
php artisan lazydocs:generate UserController

# Preview without modifying files
php artisan lazydocs:generate UserController --dry-run

# Generate for a specific method
php artisan lazydocs:generate UserController --method=store

# Process all controllers
php artisan lazydocs:generate --all
```

---

## 🔥 Before & After

### Your Controller (no PHPDoc)

```php
public function store(StoreUserRequest $request)
{
    $user = User::create($request->validated());
    
    return new UserResource($user);
}
```

### StoreUserRequest

```php
public function rules(): array
{
    return [
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'role_id' => 'required|exists:roles,id',
        'birth_date' => 'nullable|date',
    ];
}
```

### Generated PHPDoc ✨

```php
/**
 * Store a new user
 *
 * Creates a new User record in the database.
 *
 * @bodyParam name string required The name. Example: Lorem ipsum
 * @bodyParam email string required The email. Must be a valid email. Example: user@example.com
 * @bodyParam role_id integer required The role_id. Must exist in roles. Example: 1
 * @bodyParam birth_date datetime The birth_date. Example: 2025-01-15
 *
 * @response 201 {"data": {"id": 1, "name": "Lorem ipsum", "email": "user@example.com"}}
 *
 * @group User
 */
public function store(StoreUserRequest $request)
```

---

## 🧠 Smart Merge

LazyDocs preserves your existing documentation. Already have custom `@response 404`? It stays.

### Before (existing partial docs)

```php
/**
 * @response 404 {"error": "User not found"}
 */
public function show(User $user)
{
    return new UserResource($user);
}
```

### After `lazydocs:generate` 

```php
/**
 * Display the specified user
 *
 * Retrieves and displays a single User record.
 *
 * @response 200 {"data": {"id": 1, "name": "John Doe"}}
 * @response 404 {"error": "User not found"}  // ← Preserved!
 *
 * @group User
 */
public function show(User $user)
```

---

## 🔍 Code-Based Inference

LazyDocs doesn't rely on method names. It analyzes what your code **actually does**.

```php
// Method named "scassa" (not "destroy")
public function scassa(Product $product)
{
    $product->delete();  // ← Detected!
    
    return response()->json(null, Response::HTTP_NO_CONTENT);  // ← 204 detected!
}
```

**Generated:**

```php
/**
 * Remove the specified resource
 *
 * Permanently removes the specified resource.
 *
 * @response 204
 *
 * @group Product
 */
```

---

## ⚙️ Configuration

```php
// config/lazydocs.php
return [
    'controllers_path' => app_path('Http/Controllers'),
    
    'exclude_methods' => ['__construct', 'middleware'],
    
    'complexity_threshold' => 1,  // Skip trivial methods
    
    'output' => [
        'preserve_existing' => true,  // Smart merge
        'merge_strategy' => 'smart',  // 'smart' | 'overwrite'
    ],
];
```

---

## 🛣️ Roadmap

### ✅ Working Now
- FormRequest validation rules parsing
- Type inference (`exists:*` → integer, `date` → datetime)
- Eloquent operation detection (create, update, delete)
- HTTP status code detection (including constants)
- Smart merge with existing PHPDoc
- PHP 8 Attribute compatibility

### 🚧 Coming Soon
- [ ] **API Resource deep parsing** - Extract fields from `toArray()` method
- [ ] **Nested validation rules** - Support for `items.*` array rules
- [ ] **Custom response transformers** - Fractal, Spatie Data support
- [ ] **OpenAPI export** - Direct OpenAPI 3.0 generation
- [ ] **IDE plugin** - Real-time documentation preview

### 💡 Known Limitations
- Complex conditional responses may need manual `@response` tags
- Polymorphic relationships require explicit documentation
- Dynamic resource fields (when/unless) not fully supported yet

---

## 🤝 Contributing

Contributions are welcome! Please check [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

```bash
# Run tests
composer test

# Fix code style
composer pint
```

---

## 📄 License

MIT License - see [LICENSE](LICENSE) for details.

---

<p align="center">
  <b>LazyDocs</b> — Because life's too short to write PHPDoc by hand. 🦥
</p>
