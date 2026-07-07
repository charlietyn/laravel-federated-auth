# 14 - Integracion opcional con `ronu/rest-generic-class`

> Esta guia analiza como `ronu/laravel-federated-auth` puede convivir de forma homogenea con `ronu/rest-generic-class` sin quedar acoplada rigidamente a ella.
>
> Objetivo: aprovechar contratos, permisos, respuestas y convenciones comunes cuando ambas bibliotecas estan instaladas, pero permitir que `laravel-federated-auth` siga funcionando de forma independiente.

---

## 1. Resumen ejecutivo

`rest-generic-class` y `laravel-federated-auth` resuelven problemas diferentes:

| Biblioteca | Problema principal |
|---|---|
| `rest-generic-class` | Exponer CRUD RESTful generico con filtros, relaciones, paginacion, jerarquias, cache, validacion y permisos. |
| `laravel-federated-auth` | Autenticar usuarios mediante proveedores externos como Google, Facebook, Apple, Keycloak u OIDC y vincularlos con usuarios locales. |

No deben fusionarse.

La integracion correcta es:

```text
laravel-federated-auth
    autentica identidad externa
    resuelve/provisiona usuario local
    emite token local
    opcionalmente consulta permisos/roles via rest-generic-class si esta disponible
```

Y:

```text
rest-generic-class
    sigue exponiendo CRUD, filtros y permisos
    puede usar el usuario autenticado por laravel-federated-auth
    puede proteger campos/endpoints con roles y permisos efectivos
```

Principio central:

```text
Autenticacion federada desacoplada.
Autorizacion y exposicion REST homogeneas cuando RGC esta presente.
```

---

## 2. Que ofrece `rest-generic-class`

Segun su README, `rest-generic-class` proporciona clases base para CRUD RESTful con filtrado dinamico, carga de relaciones y listados jerarquicos.

Su quickstart se basa en tres piezas:

```text
BaseModel
BaseService
RestController
```

Ejemplo mental:

```text
Product extends BaseModel
ProductService extends BaseService
ProductController extends RestController
```

El controller generico procesa parametros como:

```text
relations
_nested
soft_delete
attr / eq
select
pagination
orderby
oper
hierarchy
```

Luego delega en el service:

```php
$params = $this->process_request($request);
return $this->service->list_all($params);
```

Esto significa que RGC es una excelente base para endpoints de lectura/escritura de entidades, no necesariamente para el callback OAuth.

---

## 3. Diferencia de responsabilidades

### 3.1. `laravel-federated-auth`

Debe encargarse de:

- construir redirect URL hacia Google/Facebook/Apple/Keycloak;
- validar callback;
- validar state, nonce y PKCE;
- normalizar identidad externa;
- buscar vinculo externo;
- crear usuario local si corresponde;
- sincronizar roles si aplica;
- emitir token local.

### 3.2. `rest-generic-class`

Debe encargarse de:

- CRUD generico;
- filtros dinamicos;
- carga controlada de relaciones;
- paginacion;
- jerarquias;
- exportacion;
- cache de lecturas;
- permisos efectivos;
- restriccion de campos por rol.

### 3.3. Limite sano

`laravel-federated-auth` no debe hacer esto:

```text
extends RestController
usa BaseService obligatoriamente
requiere BaseModel
requiere rutas RGC
requiere Spatie directamente
```

Porque eso convertiria la biblioteca de auth en una biblioteca dependiente de CRUD.

Si manana un proyecto usa Laravel puro, Sanctum y modelos simples, la auth debe funcionar igual.

---

## 4. Puntos de homogeneidad recomendados

La integracion debe ser opcional por capas.

```text
Nivel 0: Sin integracion
Nivel 1: Respuesta homogenea
Nivel 2: Permisos efectivos en respuesta de login
Nivel 3: RoleMapper compatible con contratos RGC
Nivel 4: Modelos de social accounts compatibles con BaseModel/BaseService
Nivel 5: Endpoints administrativos CRUD sobre social accounts usando RGC
```

---

## 5. Nivel 0 - Sin integracion

`laravel-federated-auth` funciona solo.

```text
Google -> ExternalIdentity -> User -> Token
```

No necesita RGC.

Esto debe mantenerse siempre.

---

## 6. Nivel 1 - Respuesta homogenea

RGC suele devolver estructuras como:

```json
{
  "success": true,
  "model": { }
}
```

O:

```json
{
  "data": [ ]
}
```

`laravel-federated-auth` devuelve algo como:

```json
{
  "success": true,
  "user": { },
  "token": "...",
  "token_type": "bearer",
  "was_provisioned": true,
  "was_linked": true,
  "metadata": []
}
```

La homogeneidad recomendada no es forzar exactamente el mismo formato CRUD, sino permitir una envoltura comun:

```json
{
  "ok": true,
  "data": {
    "user": { },
    "auth": {
      "access_token": "...",
      "token_type": "bearer",
      "expires_in": 3600
    },
    "federated": {
      "provider": "google",
      "was_provisioned": true,
      "was_linked": true
    }
  },
  "meta": {
    "request_id": "...",
    "channel": "mobile"
  }
}
```

Propuesta tecnica:

```php
interface AuthResponseFormatterInterface
{
    public function format(AuthResult $result, AuthContext $context): array;
}
```

Implementaciones:

```text
DefaultAuthResponseFormatter
RestGenericClassAuthResponseFormatter
```

Config:

```php
'bindings' => [
    AuthResponseFormatterInterface::class => DefaultAuthResponseFormatter::class,
]
```

Si el proyecto quiere estilo RGC:

```php
AuthResponseFormatterInterface::class => RestGenericClassAuthResponseFormatter::class,
```

---

## 7. Nivel 2 - Permisos efectivos en respuesta de login

RGC tiene una capa de permisos que permite obtener permisos efectivos del usuario:

```text
direct permissions ∪ permissions via roles
```

En RGC, el modelo User debe implementar:

```php
ProvidesRoles
```

Y cada Role debe implementar:

```php
ProvidesRolePermissions
```

El resolver central es:

```php
UserRolesResolver
```

Flujo recomendado al hacer login federado:

```text
1. Usuario autentica con Google/Apple/etc.
2. laravel-federated-auth obtiene usuario local.
3. Se emite token local.
4. Si RGC esta instalado y el usuario implementa ProvidesRoles:
       se anexan permisos efectivos al payload.
5. Si no, se omite silenciosamente.
```

Respuesta ejemplo:

```json
{
  "success": true,
  "user": {
    "id": 25,
    "email": "client@example.com",
    "user_type": "Client"
  },
  "access_token": "jwt-local-token",
  "permissions": {
    "count": 3,
    "items": [
      "appointments.view",
      "pets.create",
      "profile.update"
    ]
  }
}
```

Regla importante:

```text
No hacer require obligatorio de RGC.
Detectar clases/interfaces en runtime.
```

Ejemplo:

```php
if (
    interface_exists(\Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles::class)
    && $user instanceof \Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles
) {
    // anexar permisos efectivos
}
```

---

## 8. Nivel 3 - RoleMapper compatible con RGC

`laravel-federated-auth` ya tiene:

```php
RoleMapperInterface
```

El mapper puede sincronizar roles desde Keycloak/OIDC hacia el usuario local.

RGC ya tiene una arquitectura de permisos por contratos:

```text
User -> ProvidesRoles
Role -> ProvidesRolePermissions
UserRolesResolver
```

La integracion recomendada es crear un mapper opcional:

```php
final class RestGenericRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void
    {
        // Solo sincronizar si el proveedor es confiable para roles.
        if (!in_array($identity->provider, ['keycloak', 'oidc'], true)) {
            return;
        }

        // Mapear grupos/roles externos a roles locales.
        // No asumir Spatie directamente aqui si quieres mantener flexibilidad.
    }
}
```

Recomendacion de seguridad:

```text
Google/Facebook/Apple no deben asignar roles administrativos.
Keycloak/OIDC empresarial si puede mapear roles, pero con allowlist.
```

Ejemplo de allowlist:

```php
'role_mapping' => [
    'keycloak' => [
        'kwikvet-admin' => 'Admin',
        'kwikvet-vet' => 'Veterinarian',
        'kwikvet-client' => 'Client',
    ],
]
```

---

## 9. Nivel 4 - Modelo de identidad externa compatible con BaseModel

RGC podria gestionar administrativamente la tabla de vinculos externos si existe un modelo como:

```php
use Ronu\RestGenericClass\Core\Models\BaseModel;

class FederatedIdentity extends BaseModel
{
    protected $table = 'federated_auth_identities';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'provider_email_verified',
        'provider_name',
        'provider_avatar',
        'claims',
        'metadata',
        'last_login_at',
    ];

    const MODEL = 'federated_identity';

    const RELATIONS = ['user'];

    protected $casts = [
        'claims' => 'array',
        'metadata' => 'array',
        'provider_email_verified' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(config('federated-auth.user.model'), 'user_id');
    }
}
```

Esto permitiria endpoints administrativos como:

```http
GET /api/admin/federated-identities?relations=["user:id,email,name"]
```

Pero cuidado:

```text
No exponer access_token ni refresh_token por CRUD.
No permitir update libre de provider_user_id.
No permitir create manual sin reglas.
No permitir delete sin auditoria.
```

---

## 10. Nivel 5 - CRUD administrativo opcional

Si un proyecto quiere administrar vinculos externos desde un panel, puede crear:

```php
class FederatedIdentityService extends BaseService
{
    public function __construct()
    {
        parent::__construct(FederatedIdentity::class);
    }
}
```

```php
class FederatedIdentityController extends RestController
{
    protected $modelClass = FederatedIdentity::class;

    public function __construct(FederatedIdentityService $service)
    {
        $this->service = $service;
    }
}
```

Rutas:

```php
Route::middleware(['api', 'auth:api', 'permission:security.federated-identities.index'])
    ->prefix('admin')
    ->group(function () {
        Route::apiResource('federated-identities', FederatedIdentityController::class)
            ->only(['index', 'show']);
    });
```

Recomendacion:

```text
Solo index/show al inicio.
No habilitar store/update/destroy generico para vinculos de identidad.
```

Si necesitas desvincular, usa el endpoint seguro de `laravel-federated-auth`:

```http
DELETE /api/auth/federated/{provider}/unlink
```

No un DELETE generico directo sobre la tabla.

---

## 11. Integracion de cache

RGC cachea operaciones de lectura como `list_all` y `get_one`.

La auth federada no deberia cachear:

- callbacks OAuth;
- intercambio de code por token;
- validacion de state;
- emision de token local;
- provisionamiento de usuario.

Si se expone un CRUD administrativo de `FederatedIdentity`, ese CRUD si puede usar cache para listados, siempre que:

```text
create/update/delete/unlink bump cache version o invaliden cache.
```

RGC ya sube una version de cache cuando una escritura exitosa ocurre en `create`, `update`, `destroy` y operaciones relacionadas.

Recomendacion:

```text
No mezclar cache de RGC con cache de OAuth state.
```

Dos caches distintas:

| Cache | Uso |
|---|---|
| OAuth state cache | Seguridad de login redirect, TTL corto, one-time. |
| RGC read cache | Optimizar listados/lecturas CRUD, TTL configurable. |

---

## 12. Integracion de filtros

RGC soporta filtros `oper` con operadores como:

```text
=, !=, <, >, <=, >=, like, not like, ilike, in, not in, between, null, not null, date, regexp
```

Esto es util para paneles administrativos:

```http
GET /api/admin/federated-identities
```

Body o query:

```json
{
  "oper": {
    "and": [
      "provider|=|google",
      "provider_email_verified|=|true"
    ]
  },
  "relations": ["user:id,email,name"],
  "pagination": {
    "page": 1,
    "pageSize": 20
  }
}
```

No usar filtros dinamicos para login OAuth.

Los callbacks OAuth deben ser rutas explicitas y controladas, no endpoints genericos.

---

## 13. Integracion de field-level security

RGC tiene `FilterRequestByRole`, que puede eliminar o rechazar campos que un usuario no puede modificar.

Esto es muy util para entidades de negocio.

Ejemplo:

```php
protected array $fieldsByRole = [
    'Admin' => ['status_id', 'user_type'],
    'SuperAdmin' => ['is_superuser'],
];
```

Pero para identidades federadas se recomienda ser mas estricto:

```text
provider_user_id: nunca modificable por CRUD
provider: nunca modificable por CRUD
user_id: nunca modificable por CRUD salvo proceso interno controlado
access_token: nunca visible
refresh_token: nunca visible
claims: visible solo para auditoria admin
```

Un modelo administrativo podria declarar:

```php
protected $hidden = [
    'access_token',
    'refresh_token',
];

protected array $fieldsByRole = [
    'SuperAdmin' => ['metadata'],
];
```

Aun asi, para cambios sensibles preferir servicios explicitos.

---

## 14. Permisos recomendados para endpoints federados

Separar permisos CRUD de permisos de acciones.

### Login publico

No requiere permiso:

```text
GET  /api/auth/federated/{provider}/redirect
GET  /api/auth/federated/{provider}/callback
POST /api/auth/federated/{provider}/token
```

Pero debe tener:

```text
rate limit
state validation
provider enabled check
allowed user types
```

### Vinculacion propia

Requiere usuario autenticado:

```text
POST /api/auth/federated/{provider}/link/token
DELETE /api/auth/federated/{provider}/unlink
```

Permiso opcional:

```text
profile.external-identities.manage
```

### Administracion

Permisos sugeridos:

```text
security.federated-identities.index
security.federated-identities.show
security.federated-identities.unlink-any
security.federated-identities.audit
```

No recomiendo:

```text
security.federated-identities.create
security.federated-identities.update
```

Porque crear/modificar vinculos manualmente puede abrir account takeover.

---

## 15. Como detectar RGC sin acoplar

Nunca hacer esto en `composer.json` de `laravel-federated-auth`:

```json
"ronu/rest-generic-class": "^x.y"
```

como dependencia obligatoria.

Mejor:

```json
"suggest": {
  "ronu/rest-generic-class": "Optional integration for REST/permissions response enrichment"
}
```

Y en runtime:

```php
$rgcAvailable = interface_exists(
    \Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles::class
);
```

Esto permite:

```text
Proyecto A: solo federated-auth
Proyecto B: federated-auth + rest-generic-class
Proyecto C: federated-auth + Spatie directo
Proyecto D: federated-auth + permisos propios
```

---

## 16. Arquitectura propuesta de integracion opcional

```text
laravel-federated-auth
│
├── Core obligatorio
│   ├── FederatedAuthBroker
│   ├── ProviderAdapters
│   ├── IdentityLinkRepository
│   ├── UserResolver
│   ├── UserProvisioner
│   ├── RoleMapper
│   └── TokenIssuer
│
└── Integrations opcionales
    └── RestGenericClass
        ├── RestGenericPermissionPayloadResolver
        ├── RestGenericRoleMapper
        ├── RestGenericAuthResponseFormatter
        └── FederatedIdentity admin model example
```

Namespace recomendado:

```php
Ronu\LaravelFederatedAuth\Integrations\RestGenericClass
```

Clases sugeridas:

```text
RestGenericClassDetector
RestGenericPermissionsResolver
RestGenericAuthResponseFormatter
RestGenericRoleMapper
```

---

## 17. Contratos sugeridos para `laravel-federated-auth`

### 17.1. PermissionPayloadResolverInterface

```php
interface PermissionPayloadResolverInterface
{
    public function resolve(Authenticatable $user, AuthContext $context): array;
}
```

Default:

```php
NullPermissionPayloadResolver
```

RGC:

```php
RestGenericPermissionPayloadResolver
```

### 17.2. AuthResponseFormatterInterface

```php
interface AuthResponseFormatterInterface
{
    public function format(AuthResult $result, AuthContext $context): array;
}
```

Default:

```php
DefaultAuthResponseFormatter
```

RGC style:

```php
RestGenericAuthResponseFormatter
```

### 17.3. IdentityAdminPresenterInterface

Para vistas administrativas:

```php
interface IdentityAdminPresenterInterface
{
    public function present(LinkedIdentity $identity): array;
}
```

Esto evita exponer tokens o claims sensibles por accidente.

---

## 18. Flujo ideal con ambas bibliotecas

```text
1. Mobile app manda id_token de Apple.
2. laravel-federated-auth valida Apple id_token.
3. Se obtiene ExternalIdentity(provider=apple, providerUserId=sub).
4. Se busca vinculo externo.
5. Se resuelve usuario local.
6. UserStatusChecker valida estado.
7. RoleMapper sincroniza rol Client si aplica.
8. TokenIssuer emite JWT.
9. Si RGC esta disponible:
      - PermissionPayloadResolver obtiene permisos efectivos.
      - AuthResponseFormatter construye respuesta homogenea.
10. Frontend recibe user + token + permissions.
```

Respuesta ideal:

```json
{
  "ok": true,
  "data": {
    "user": {
      "id": 25,
      "email": "client@example.com",
      "user_type": "Client"
    },
    "auth": {
      "access_token": "jwt",
      "token_type": "bearer",
      "expires_in": 3600
    },
    "permissions": {
      "count": 3,
      "permissions": [
        {"name": "appointments.index", "module": "medical", "guard": "api"},
        {"name": "pets.create", "module": "clients", "guard": "api"}
      ]
    },
    "federated": {
      "provider": "apple",
      "was_provisioned": false,
      "was_linked": false
    }
  }
}
```

---

## 19. Que NO debe hacerse

No hacer:

```text
- Hacer que laravel-federated-auth extienda RestController.
- Usar BaseService para callbacks OAuth.
- Exponer federated_auth_identities con apiResource completo sin restricciones.
- Permitir update generico de provider_user_id.
- Guardar provider access_token por defecto.
- Depender obligatoriamente de RGC en composer.
- Usar filtros dinamicos para procesos de login.
- Mapear roles admin desde Google/Facebook/Apple.
```

---

## 20. Roadmap recomendado

### Fase 1 - Documental

- Mantener esta guia.
- Anadir ejemplos de integracion en `/examples/rest-generic-class`.

### Fase 2 - Contratos opcionales

Agregar a `laravel-federated-auth`:

```text
PermissionPayloadResolverInterface
AuthResponseFormatterInterface
```

Sin mencionar RGC en el core.

### Fase 3 - Adapter opcional RGC

Agregar clases bajo:

```text
src/Integrations/RestGenericClass
```

Estas clases solo deben activarse si las interfaces de RGC existen.

### Fase 4 - Ejemplo administrativo

Crear ejemplo:

```text
examples/rest-generic-class/FederatedIdentity.php
examples/rest-generic-class/FederatedIdentityService.php
examples/rest-generic-class/FederatedIdentityController.php
```

Solo `index` y `show`.

### Fase 5 - Tests

- Test sin RGC instalado.
- Test con fake ProvidesRoles.
- Test response formatter default.
- Test response formatter RGC-like.
- Test que no se expongan tokens externos.

---

## 21. Veredicto arquitectonico

La integracion tiene mucho sentido, pero no por herencia ni dependencia directa.

La mejor arquitectura es:

```text
federated-auth core independiente
+ contratos pequenos
+ integracion opcional con rest-generic-class
+ ejemplos documentados
```

Eso mantiene las dos bibliotecas alineadas con el mismo estilo de startup/framework:

```text
contratos explicitos
respuestas homogeneas
permisos efectivos
modelos configurables
seguridad por defecto
bajo acoplamiento
```

La frase clave:

```text
RGC puede enriquecer la experiencia de autorizacion y administracion,
pero no debe ser requisito para autenticar.
```
