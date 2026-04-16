# Domain Folder Organization

- Organize business modules into **singular PascalCase** subfolders under each Laravel layer: `Actions/Project/`, `Models/Team/`, `Http/Controllers/Project/`, etc.
- Create subfolders **incrementally** when building each phase — not pre-emptively. Use the **3+ files threshold**: create a subfolder when a domain accumulates 3+ files in that layer.
- Auth/framework files (`User*Controller`, `CreateSessionRequest`, `CreateUserPassword`, `User.php`) stay at the **root** of each layer — they are cross-cutting plumbing, not a business domain.
- Place interfaces **next to their implementations** (e.g., `Services/Payment/PaymentGatewayInterface.php` alongside `Services/Payment/StripeGateway.php`). Do not create a separate `Contracts/` directory.
- Cross-domain types (shared enums, value objects) live in shared locations: `app/Enums/`, `app/ValueObjects/`.
- Wayfinder auto-adapts to controller namespace changes — no manual configuration needed.

@boostsnippet('Creating domain-organized files with Artisan', 'bash')
# Models
php artisan make:model Project/Project --no-interaction
php artisan make:model Project/ProjectMilestone --no-interaction

# Controllers
php artisan make:controller Project/ProjectController --resource --no-interaction

# Form Requests
php artisan make:request Project/StoreProjectRequest --no-interaction

# Actions
php artisan make:action "Project/CompleteProject" --no-interaction

# Enums (manual or via package)
# Namespace: App\Enums\Project\ProjectStatus
@endboostsnippet

@boostsnippet('Domain folder mapping by phase', 'text')
Phase 1 → (root)     Auth plumbing: User model, session controllers
Phase 2 → Project/   Project, ProjectMilestone, ProjectStatus
Phase 3 → Team/      TeamMember, TeamRole, Department
@endboostsnippet
