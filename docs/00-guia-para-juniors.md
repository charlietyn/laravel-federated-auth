# 00 - Guia extensa para juniors: como usar `ronu/laravel-federated-auth`

> Esta guia esta escrita para desarrolladores junior o aprendices que quieren entender como funciona el login federado en Laravel usando Google, Facebook, Apple, Keycloak u otro proveedor OIDC.
>
> La idea no es solo copiar codigo. La idea es que entiendas que problema resuelve la biblioteca, que datos se guardan, que flujo ocurre por dentro y como se conecta con tu propio sistema de usuarios, roles y permisos.

---

## 1. Que problema resuelve esta biblioteca

Normalmente, cuando una aplicacion Laravel necesita login con Google, Facebook o Apple, muchos desarrolladores hacen algo asi:

```text
Boton Google -> Socialite -> buscar usuario por email -> crear usuario -> login
```

Eso puede funcionar en proyectos pequenos, pero en sistemas reales aparecen problemas:

- tu tabla de usuarios no siempre se llama `users`;
- tu modelo no siempre es `App\Models\User`;
- el email no siempre es unico;
- un mismo email puede existir como cliente, veterinario, tecnico o administrador;
- Google, Facebook y Apple no devuelven los mismos datos;
- Apple puede devolver un email privado relay;
- Facebook a veces no devuelve email;
- tu API puede usar JWT, Sanctum, cookies de sesion u otro sistema;
- un usuario local puede tener varias identidades externas vinculadas;
- no todos los usuarios deben poder auto-registrarse;
- un administrador no debe crearse automaticamente porque alguien hizo login con Google.

Esta biblioteca organiza ese problema usando una arquitectura por responsabilidades:

```text
Proveedor externo
    -> Adapter
    -> ExternalIdentity
    -> Broker
    -> Resolver/Provisioner
    -> Identity Link
    -> Role Mapper
    -> Token Issuer
```

En palabras simples:

```text
El proveedor dice quien es la persona.
Tu aplicacion decide quien es localmente y que permisos tiene.
```

---

## 2. Conceptos basicos antes de tocar codigo

### 2.1. Autenticacion

Autenticacion significa responder:

```text
Quien eres?
```

Ejemplo:

```text
Soy carlos@example.com y Google confirma que esta cuenta existe.
```

### 2.2. Autorizacion

Autorizacion significa responder:

```text
Que puedes hacer?
```

Ejemplo:

```text
Puedes crear citas, pero no puedes administrar usuarios.
```

### 2.3. OAuth2

OAuth2 es principalmente un protocolo de autorizacion. Permite que una aplicacion acceda a recursos de otro sistema con permiso del usuario.

Ejemplo:

```text
Permitir que mi app lea tu email de Google.
```

### 2.4. OpenID Connect / OIDC

OIDC es una capa encima de OAuth2 que sirve para autenticacion.

OIDC permite obtener un `id_token`, que normalmente contiene claims como:

```json
{
  "sub": "1234567890",
  "email": "client@example.com",
  "email_verified": true,
  "name": "Client Example"
}
```

### 2.5. Provider user id / `sub`

Este es el identificador real del usuario en el proveedor.

Para Google, Apple, Keycloak u OIDC suele venir como:

```text
sub
```

Para Facebook puede venir como:

```text
id
```

La regla de oro es:

```text
No uses el email como identidad primaria.
Usa provider + provider_user_id.
```

Ejemplo:

```text
google + 107691503500061507151
apple + 000123.abc456def789
facebook + 123456789
```

---

## 3. Que guarda tu sistema local

El proveedor externo no reemplaza tu base de datos local.

Tu aplicacion debe seguir teniendo una tabla local de usuarios, por ejemplo:

```text
users
```

O en un sistema modular:

```text
mod_security.users
```

La biblioteca crea o usa una tabla de vinculos externos:

```text
federated_auth_identities
```

Esa tabla responde:

```text
Esta cuenta de Google/Facebook/Apple corresponde a que usuario local?
```

Ejemplo:

| id | user_id | provider | provider_user_id | provider_email |
|---:|---:|---|---|---|
| 1 | 25 | google | 107691503500061507151 | client@example.com |
| 2 | 25 | apple | 000123.abc456def789 | private@privaterelay.appleid.com |

En este ejemplo, el usuario local `25` puede entrar con Google o Apple.

---

## 4. Instalacion basica

```bash
composer require ronu/laravel-federated-auth
php artisan vendor:publish --tag=federated-auth-config
php artisan vendor:publish --tag=federated-auth-migrations
php artisan migrate
```

Despues de publicar la configuracion, revisa:

```text
config/federated-auth.php
```

Ahi se definen:

- proveedores habilitados;
- rutas;
- modelo de usuario local;
- columnas de usuario;
- tabla de vinculos externos;
- reglas de seguridad;
- contratos personalizados.

---

## 5. Configuracion minima para un proyecto Laravel normal

Supongamos un proyecto con:

```text
App\Models\User
users.id
users.name
users.email
users.password
```

Configura:

```php
'user' => [
    'model' => App\Models\User::class,
    'primary_key' => 'id',
    'columns' => [
        'id' => 'id',
        'email' => 'email',
        'name' => 'name',
        'password' => 'password',
        'status' => null,
        'type' => null,
    ],
],
```

Si tu aplicacion no tiene estados de usuario, puedes dejar `status` como `null`.

Si tu aplicacion no diferencia tipos de usuario, puedes dejar `type` como `null`.

---

## 6. Configuracion para un sistema modular tipo KwikVet

Supongamos:

```text
Modules\mod_security\Models\Users
mod_security.users
status_id
user_type
```

Configura:

```php
'user' => [
    'model' => Modules\mod_security\Models\Users::class,
    'connection' => 'pgsql',
    'table' => 'mod_security.users',
    'primary_key' => 'id',
    'columns' => [
        'id' => 'id',
        'email' => 'email',
        'name' => 'name',
        'username' => 'username',
        'password' => 'password',
        'avatar' => 'avatar',
        'status' => 'status_id',
        'type' => 'user_type',
    ],
    'active_status_values' => [1, '1', true, 'active', 'enabled'],
],
```

Y la tabla de vinculos externos puede vivir en tu schema de seguridad:

```php
'identity_store' => [
    'connection' => 'pgsql',
    'table' => 'mod_security.social_accounts',
    'tenant_column' => 'tenant_id',
    'user_id_column' => 'user_id',
    'store_provider_tokens' => false,
    'encrypt_provider_tokens' => true,
],
```

---

## 7. Flujo de login web con Google

### 7.1. El usuario pulsa el boton

Frontend:

```html
<a href="https://api.example.com/api/auth/federated/google/redirect">
    Continuar con Google
</a>
```

### 7.2. Laravel recibe la peticion

```http
GET /api/auth/federated/google/redirect
```

La biblioteca:

1. valida que Google este habilitado;
2. crea un `state` de un solo uso;
3. opcionalmente genera `nonce`;
4. guarda metadata como IP, user-agent, tenant, channel;
5. redirige a Google.

### 7.3. Google autentica al usuario

Google muestra su pantalla:

```text
Elige una cuenta
Permitir acceso a perfil y email
```

### 7.4. Google devuelve al callback

```http
GET /api/auth/federated/google/callback?code=abc&state=xyz
```

La biblioteca:

1. consume el `state`;
2. rechaza si el state no existe, expiro o ya se uso;
3. obtiene los datos del usuario desde Google;
4. crea un `ExternalIdentity`;
5. busca si ya hay una cuenta vinculada;
6. si no existe, provisiona usuario si esta permitido;
7. crea el vinculo externo;
8. emite tu token local.

### 7.5. Respuesta esperada

```json
{
  "success": true,
  "user": {
    "id": 25,
    "name": "Client Example",
    "email": "client@example.com",
    "user_type": "Client",
    "auth_identifier": 25
  },
  "token": "jwt-local-token",
  "access_token": "jwt-local-token",
  "token_type": "bearer",
  "was_provisioned": true,
  "was_linked": true,
  "metadata": []
}
```

---

## 8. Flujo de login para movil o SPA

En movil, muchas veces no se usa redirect del backend. Se usa el SDK nativo.

Ejemplo:

```text
React Native App -> Google SDK -> access_token -> Laravel API
```

La app llama:

```http
POST /api/auth/federated/google/token
Content-Type: application/json

{
  "access_token": "provider-access-token",
  "user_type": "Client",
  "channel": "mobile"
}
```

Laravel valida el token con el provider y despues ejecuta el mismo flujo local.

Para Apple movil, normalmente se envia `id_token`:

```http
POST /api/auth/federated/apple/token
Content-Type: application/json

{
  "id_token": "apple-identity-token",
  "user_type": "Client",
  "channel": "mobile"
}
```

---

## 9. Que es `ExternalIdentity`

`ExternalIdentity` es una version normalizada de los datos que devuelve cada proveedor.

Google, Facebook, Apple y Keycloak devuelven estructuras diferentes. La biblioteca las convierte a una estructura comun:

```text
provider
providerUserId
email
emailVerified
name
firstName
lastName
avatarUrl
claims
groups
roles
accessToken
refreshToken
expiresIn
```

Ejemplo con Google:

```php
new ExternalIdentity(
    provider: 'google',
    providerUserId: '107691503500061507151',
    email: 'client@example.com',
    emailVerified: true,
    name: 'Client Example',
    avatarUrl: 'https://lh3.googleusercontent.com/...'
);
```

Ejemplo con Apple:

```php
new ExternalIdentity(
    provider: 'apple',
    providerUserId: '000123.abc456def789',
    email: 'private@privaterelay.appleid.com',
    emailVerified: true,
    name: null
);
```

---

## 10. Que es `AuthContext`

`AuthContext` transporta informacion de la peticion actual.

Ejemplo:

```text
provider = google
tenantId = clinic-1
userType = Client
channel = mobile
guard = api
redirectUri = https://api.example.com/callback
state = xyz
metadata = ip + user_agent
```

Esto permite que la misma biblioteca funcione en escenarios diferentes:

```text
admin panel
mobile app
client portal
multi-tenant API
enterprise login
```

---

## 11. Que es `FederatedAuthBroker`

El broker es el coordinador principal.

No habla directamente con Google. No crea usuarios por su cuenta. No decide permisos finales por si solo.

Coordina piezas:

```text
Provider Adapter
IdentityLinkRepository
UserResolver
UserProvisioner
UserStatusChecker
RoleMapper
TokenIssuer
```

Piensalo como un director de orquesta.

---

## 12. Registro automatico: `UserProvisionerInterface`

Cuando un usuario entra por primera vez y no existe vinculo externo, pueden pasar dos cosas:

### Caso A: auto-provision desactivado

```text
No existe usuario local -> denegar login
```

Esto es recomendado para administradores, tecnicos, veterinarios o usuarios empresariales.

### Caso B: auto-provision activado

```text
No existe usuario local -> crear usuario local -> crear perfil -> asignar rol -> crear vinculo -> login
```

Para eso debes implementar:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;

final class ClientUserProvisioner implements UserProvisionerInterface
{
    public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable
    {
        return DB::transaction(function () use ($identity, $context) {
            $user = User::query()->create([
                'name' => $identity->name ?: 'New Client',
                'email' => $identity->email,
                'password' => Hash::make(Str::random(40)),
                'status_id' => 1,
                'user_type' => $context->userType ?: 'Client',
                'avatar' => $identity->avatarUrl,
            ]);

            Client::query()->create([
                'user_id' => $user->id,
                'profile_completed' => false,
            ]);

            $user->assignRole('Client');

            return $user;
        });
    }
}
```

Luego lo registras en config:

```php
'bindings' => [
    Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface::class => App\Auth\ClientUserProvisioner::class,
],
```

---

## 13. Resolver usuarios existentes: `UserResolverInterface`

El resolver busca usuarios locales.

La biblioteca trae un resolver configurable que puede buscar:

```text
por id
por email
por email + user_type
```

Pero en sistemas complejos puedes necesitar uno propio.

Ejemplo:

```php
final class TenantAwareUserResolver implements UserResolverInterface
{
    public function resolveById(string|int $userId, AuthContext $context): ?Authenticatable
    {
        return User::query()
            ->where('id', $userId)
            ->where('tenant_id', $context->tenantId)
            ->first();
    }

    public function resolveByExternalIdentity(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return null;
    }

    public function resolveByEmail(ExternalIdentity $identity, AuthContext $context): ?Authenticatable
    {
        return User::query()
            ->where('email', $identity->email)
            ->where('tenant_id', $context->tenantId)
            ->where('user_type', $context->userType)
            ->first();
    }
}
```

---

## 14. Emision de token local: `TokenIssuerInterface`

El provider externo no debe ser el token final de tu app.

Flujo correcto:

```text
Google token -> validar identidad -> emitir token local de mi API
```

La biblioteca permite emitir:

- JWT;
- Sanctum token;
- cookie de sesion;
- token personalizado.

Ejemplo JWT:

```php
final class ApiJwtTokenIssuer implements TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        $token = auth('api')->login($user);

        return new AuthResult($user, [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL(),
        ]);
    }
}
```

---

## 15. Roles y permisos: donde se conectan

No confundas login federado con permisos.

El proveedor dice:

```text
Esta persona es la cuenta Google X.
```

Tu sistema dice:

```text
Esta persona local es Client y puede crear citas.
```

### Regla recomendada

```text
Google/Facebook/Apple -> solo auto-provisionan Client.
Admin/Veterinarian/Technician -> requieren validacion interna.
Keycloak/OIDC enterprise -> puede mapear roles externos si confias en ese IdP.
```

Ejemplo `RoleMapperInterface`:

```php
final class AppRoleMapper implements RoleMapperInterface
{
    public function sync(Authenticatable $user, ExternalIdentity $identity, AuthContext $context): void
    {
        if ($context->userType === 'Client') {
            $user->syncRoles(['Client']);
            return;
        }

        if ($identity->provider === 'keycloak') {
            if (in_array('clinic-admin', $identity->roles, true)) {
                $user->syncRoles(['Admin']);
            }
        }
    }
}
```

---

## 16. Vincular otra cuenta externa

Un usuario ya autenticado puede vincular Google, Facebook o Apple.

Ejemplo:

```http
POST /api/auth/federated/google/link/token
Authorization: Bearer local-jwt
Content-Type: application/json

{
  "access_token": "google-provider-token"
}
```

La biblioteca valida:

1. que el usuario local este autenticado;
2. que el token externo sea valido;
3. que esa identidad externa no pertenezca a otro usuario;
4. que el vinculo se pueda crear o actualizar.

---

## 17. Desvincular proveedor

```http
DELETE /api/auth/federated/google/unlink
Authorization: Bearer local-jwt
```

La biblioteca evita un error comun:

```text
Si el usuario no tiene password local y solo tiene una identidad externa,
no permite eliminar la ultima identidad.
```

Eso evita dejar al usuario sin forma de entrar.

---

## 18. Google

Config:

```env
FEDERATED_AUTH_GOOGLE_ENABLED=true
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/federated/google/callback
```

Recomendado:

```php
'google' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => true,
    'allow_email_linking' => false,
    'allowed_user_types' => ['Client'],
]
```

Usa Google para login de clientes, no para crear administradores automaticamente.

---

## 19. Facebook

Config:

```env
FEDERATED_AUTH_FACEBOOK_ENABLED=true
FACEBOOK_CLIENT_ID=your-facebook-client-id
FACEBOOK_CLIENT_SECRET=your-facebook-client-secret
FACEBOOK_REDIRECT_URI=https://api.example.com/api/auth/federated/facebook/callback
```

Facebook puede no devolver email.

Recomendado:

```php
'facebook' => [
    'require_email' => true,
    'require_verified_email' => false,
    'trust_email_as_verified' => false,
    'auto_provision' => true,
    'allow_email_linking' => false,
    'allowed_user_types' => ['Client'],
]
```

No asumas que el email de Facebook siempre es fuerte o verificado.

---

## 20. Apple

Config:

```env
FEDERATED_AUTH_APPLE_ENABLED=true
APPLE_CLIENT_ID=com.example.web
APPLE_TEAM_ID=TEAMID1234
APPLE_KEY_ID=ABC123DEFG
APPLE_PRIVATE_KEY_PATH=/secure/path/AuthKey_ABC123DEFG.p8
APPLE_REDIRECT_URI=https://api.example.com/api/auth/federated/apple/callback
```

Apple usa un `client_secret` especial, que es un JWT firmado con tu private key `.p8`.

La biblioteca puede generarlo si configuras:

```text
APPLE_TEAM_ID
APPLE_KEY_ID
APPLE_CLIENT_ID
APPLE_PRIVATE_KEY_PATH
```

Apple puede devolver email privado relay:

```text
abc123@privaterelay.appleid.com
```

Eso es normal. Guardalo como email de contacto, pero no lo uses como identidad primaria.

La identidad primaria sigue siendo:

```text
apple + sub
```

---

## 21. Keycloak / OIDC empresarial

Keycloak se usa normalmente en empresas.

Ejemplo config:

```env
FEDERATED_AUTH_KEYCLOAK_ENABLED=true
KEYCLOAK_BASE_URL=https://auth.example.com
KEYCLOAK_REALM=kwikvet
KEYCLOAK_CLIENT_ID=kwikvet-api
KEYCLOAK_CLIENT_SECRET=secret
KEYCLOAK_REDIRECT_URI=https://api.example.com/api/auth/federated/keycloak/callback
```

Recomendado:

```php
'keycloak' => [
    'require_email' => true,
    'require_verified_email' => true,
    'auto_provision' => false,
    'allow_email_linking' => false,
    'sync_roles' => true,
]
```

Para Keycloak, normalmente no quieres crear usuarios privilegiados automaticamente. Quieres que primero existan localmente o pasen por una validacion interna.

---

## 22. Seguridad explicada para juniors

### 22.1. Por que no confiar en email

Malo:

```php
$user = User::where('email', $providerEmail)->first();
```

Problemas:

- el email puede cambiar;
- el email puede no estar verificado;
- varios tipos de usuario pueden compartir email;
- Apple puede usar relay email;
- Facebook puede no entregar email;
- puede existir riesgo de account takeover si se vincula automaticamente.

Bueno:

```text
Buscar por provider + provider_user_id.
```

### 22.2. Que es `state`

`state` evita que alguien invente un callback falso.

Flujo:

```text
Laravel crea state ABC
Proveedor devuelve state ABC
Laravel consume ABC una sola vez
```

Si alguien intenta reutilizarlo:

```text
rechazado
```

### 22.3. Que es `nonce`

`nonce` protege el `id_token` en flujos OIDC.

Flujo:

```text
Laravel crea nonce N1
Proveedor mete N1 dentro del id_token
Laravel valida que el id_token contiene N1
```

### 22.4. Que es PKCE

PKCE evita que un authorization code robado pueda intercambiarse sin el `code_verifier` correcto.

Flujo:

```text
Laravel crea code_verifier secreto
Laravel envia code_challenge publico
Proveedor devuelve code
Laravel intercambia code + code_verifier
```

---

## 23. Configuracion segura recomendada

```env
FEDERATED_AUTH_ENABLED=true
FEDERATED_AUTH_ROUTES_ENABLED=true
FEDERATED_AUTH_OAUTH_STATE_ENABLED=true
FEDERATED_AUTH_OAUTH_STATE_TTL_SECONDS=300
FEDERATED_AUTH_OAUTH_STATE_BIND_USER_AGENT=true
FEDERATED_AUTH_OAUTH_STATE_BIND_IP=false
FEDERATED_AUTH_PKCE_ENABLED=true
FEDERATED_AUTH_OIDC_NONCE_ENABLED=true
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com,app.example.com
FEDERATED_AUTH_ALLOW_HTTP_LOCALHOST_REDIRECTS=false
FEDERATED_AUTH_STORE_PROVIDER_TOKENS=false
FEDERATED_AUTH_ENCRYPT_PROVIDER_TOKENS=true
```

Para desarrollo local:

```env
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=localhost,127.0.0.1
FEDERATED_AUTH_ALLOW_HTTP_LOCALHOST_REDIRECTS=true
```

---

## 24. Ejemplo completo de flujo de primera vez

Usuario nuevo entra con Google.

```text
1. Frontend abre /google/redirect
2. Laravel crea state
3. Google autentica
4. Google vuelve al callback
5. Laravel valida state
6. Laravel obtiene ExternalIdentity
7. Busca federated_auth_identities por google + provider_user_id
8. No existe
9. Como auto_provision=true, llama ClientUserProvisioner
10. Crea users
11. Crea clients
12. Asigna rol Client
13. Crea federated_auth_identities
14. Emite JWT local
15. Devuelve respuesta al frontend
```

---

## 25. Ejemplo completo de usuario existente

```text
1. Usuario entra con Apple
2. Laravel valida id_token
3. Extrae sub
4. Busca apple + sub en federated_auth_identities
5. Encuentra user_id=25
6. Carga usuario local 25
7. Verifica status activo
8. Actualiza last_login_at
9. Emite JWT local
```

No crea usuario nuevo.

---

## 26. Ejemplo completo de usuario bloqueado

```text
1. Usuario autentica bien en Google
2. Google confirma identidad
3. Laravel encuentra usuario local
4. Usuario local tiene status_id=0
5. Laravel rechaza login
```

Importante:

```text
Que Google diga que eres tu no significa que tu sistema local deba darte acceso.
```

---

## 27. Ejemplo de error por email duplicado

Supongamos:

| id | email | user_type |
|---:|---|---|
| 1 | carlos@example.com | Client |
| 2 | carlos@example.com | Veterinarian |

Si permites vincular por email sin `user_type`, el sistema no sabe a cual usuario vincular.

Por seguridad, debe rechazar:

```text
AmbiguousLocalUserException
```

Soluciones:

- pedir `user_type`;
- no permitir `allow_email_linking`;
- hacer vinculacion manual desde cuenta autenticada;
- usar un flujo de verificacion interna.

---

## 28. Errores comunes y soluciones

### Error: provider disabled

Causa:

```php
'enabled' => false
```

Solucion:

```env
FEDERATED_AUTH_GOOGLE_ENABLED=true
```

### Error: email required

Causa:

```php
'require_email' => true
```

pero el proveedor no devolvio email.

Solucion:

- revisar scopes;
- revisar permisos de la app;
- en Facebook, verificar permiso `email`;
- decidir si tu negocio permite login sin email.

### Error: email not verified

Causa:

```php
'require_verified_email' => true
```

pero el proveedor no confirmo verificacion.

Solucion:

- mantenerlo asi para Google/Apple;
- en Facebook, usar `require_verified_email=false` y tratar email como contacto.

### Error: state missing

Causa:

- callback incorrecto;
- provider no devolvio `state`;
- frontend llamo callback manualmente;
- cache se limpio;
- TTL expiro.

Solucion:

- revisar redirect URI configurado;
- revisar cache driver;
- revisar que no se pierda la sesion de redirect;
- incrementar TTL si el login tarda demasiado.

### Error: redirect host not allowed

Causa:

```env
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com
```

y estas usando:

```text
https://staging-api.example.com
```

Solucion:

```env
FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS=api.example.com,staging-api.example.com
```

---

## 29. Checklist antes de produccion

- [ ] Google configurado con redirect URI real.
- [ ] Facebook configurado y permiso email revisado.
- [ ] Apple Services ID configurado.
- [ ] Apple `.p8` guardado fuera del repo.
- [ ] `FEDERATED_AUTH_ALLOWED_REDIRECT_HOSTS` configurado.
- [ ] `FEDERATED_AUTH_OAUTH_STATE_ENABLED=true`.
- [ ] `FEDERATED_AUTH_PKCE_ENABLED=true`.
- [ ] `FEDERATED_AUTH_OIDC_NONCE_ENABLED=true`.
- [ ] `store_provider_tokens=false` salvo necesidad real.
- [ ] Admin no se auto-provisiona.
- [ ] Veterinarian no se auto-provisiona.
- [ ] Technician no se auto-provisiona.
- [ ] Client tiene provisioner propio.
- [ ] RoleMapper probado.
- [ ] TokenIssuer probado.
- [ ] Tests pasan.
- [ ] Logs no imprimen provider tokens.
- [ ] Respuesta API no expone password ni columnas internas.

---

## 30. Arquitectura mental final

Recuerda este dibujo:

```text
[Google/Facebook/Apple/Keycloak]
              |
              v
       Provider Adapter
              |
              v
       ExternalIdentity
              |
              v
      FederatedAuthBroker
              |
    +---------+---------+---------+---------+
    |         |         |         |         |
Resolver  Provisioner  LinkRepo  RoleMap  TokenIssuer
    |         |         |         |         |
    v         v         v         v         v
 Usuario   Crear      Vinculo    Roles    JWT/Sanctum
 local     usuario    externo    permisos sesion
```

La frase mas importante:

```text
La identidad externa autentica. Tu sistema local autoriza.
```

Si entiendes eso, puedes usar esta biblioteca de forma segura y profesional.
