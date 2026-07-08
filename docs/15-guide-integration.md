# 15 - Guia junior: integrar `laravel-federated-auth` con `rest-generic-class`

> Esta guia explica, paso a paso y con ejemplos sencillos, como usar la integracion opcional entre `ronu/laravel-federated-auth` y `ronu/rest-generic-class`.
>
> Objetivo: que un aprendiz entienda como devolver respuestas mas homogeneas y como incluir permisos efectivos en el login sin acoplar la autenticacion federada al CRUD generico.

---

## 1. La idea en una frase

```text
laravel-federated-auth autentica.
rest-generic-class ayuda a presentar permisos y respuestas de forma homogenea.
```

No son la misma cosa.

`laravel-federated-auth` responde:

```text
Quien eres?
```

`rest-generic-class` ayuda a responder:

```text
Que permisos tienes?
Como exponemos datos REST de forma consistente?
```

---

## 2. Que se implemento en la biblioteca

Se agregaron dos contratos pequenos:

```php
AuthResponseFormatterInterface
PermissionPayloadResolverInterface
```

Tambien se agregaron implementaciones:

```text
DefaultAuthResponseFormatter
NullPermissionPayloadResolver
RestGenericClassDetector
RestGenericPermissionPayloadResolver
RestGenericAuthResponseFormatter
```

Lectura simple:

| Clase | Para que sirve |
|---|---|
| `AuthResponseFormatterInterface` | Define como se formatea la respuesta final del login. |
| `DefaultAuthResponseFormatter` | Mantiene la respuesta clasica del paquete. |
| `RestGenericAuthResponseFormatter` | Devuelve una respuesta estilo `ok/data/meta`. |
| `PermissionPayloadResolverInterface` | Define como anexar permisos al login. |
| `NullPermissionPayloadResolver` | No agrega permisos; es seguro por defecto. |
| `RestGenericPermissionPayloadResolver` | Si RGC esta instalado y el usuario cumple contratos, anexa permisos efectivos. |
| `RestGenericClassDetector` | Detecta en runtime si RGC esta disponible. |

---

## 3. Por que se hizo con contratos

Un junior podria pensar:

```text
Si queremos usar RGC, pongamos RGC como dependencia obligatoria.
```

Eso seria un error.

La autenticacion federada debe poder funcionar en proyectos que no usan RGC.

Por eso se hizo asi:

```text
Core independiente
    + contratos pequenos
    + integracion opcional
```

Esto permite cuatro escenarios:

```text
Proyecto A: solo laravel-federated-auth
Proyecto B: laravel-federated-auth + rest-generic-class
Proyecto C: laravel-federated-auth + Spatie directo
Proyecto D: laravel-federated-auth + permisos propios
```

---

## 4. Instalacion opcional

Primero instalas la auth:

```bash
composer require ronu/laravel-federated-auth
```

Si tambien quieres RGC:

```bash
composer require ronu/rest-generic-class
```

Importante:

```text
ronu/rest-generic-class aparece como suggest, no como require.
```

Eso significa:

```text
Recomendado para integracion opcional, pero no obligatorio.
```

---

## 5. Configuracion basica sin RGC

Por defecto, el paquete usa:

```php
PermissionPayloadResolverInterface::class => NullPermissionPayloadResolver::class,
AuthResponseFormatterInterface::class => DefaultAuthResponseFormatter::class,
```

Esto significa:

```text
No se anexan permisos.
La respuesta se mantiene parecida al formato original.
```

Ejemplo de respuesta:

```json
{
  "success": true,
  "user": {
    "id": 25,
    "email": "client@example.com",
    "user_type": "Client",
    "auth_identifier": 25
  },
  "access_token": "jwt-token",
  "token_type": "bearer",
  "was_provisioned": false,
  "was_linked": false,
  "metadata": []
}
```

---

## 6. Activar permisos RGC en respuesta de login

En `config/federated-auth.php`, cambia los bindings:

```php
use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\PermissionPayloadResolverInterface;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericAuthResponseFormatter;
use Ronu\LaravelFederatedAuth\Integrations\RestGenericClass\RestGenericPermissionPayloadResolver;

'bindings' => [
    // otros bindings...

    PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
    AuthResponseFormatterInterface::class => RestGenericAuthResponseFormatter::class,
],
```

Activa permisos en la respuesta:

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
FEDERATED_AUTH_RGC_ENABLED=true
```

Nota:

```text
FEDERATED_AUTH_RGC_ENABLED documenta la intencion del proyecto.
El detector real verifica si las interfaces de RGC existen.
```

---

## 7. Como debe estar preparado tu User

Para que RGC pueda devolver permisos, tu usuario debe implementar:

```php
Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles
```

Ejemplo usando Spatie:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements ProvidesRoles
{
    use HasRoles;
    use HasReadableUserPermissions;

    // Si tu relacion de roles se llama 'roles', no necesitas declarar nada mas.
}
```

Si tu relacion no se llama `roles`, por ejemplo se llama `array_role`:

```php
class User extends Authenticatable implements ProvidesRoles
{
    use HasReadableUserPermissions;

    const ROLES_RELATION = 'array_role';

    public function array_role()
    {
        return $this->belongsToMany(Role::class, 'role_users');
    }
}
```

---

## 8. Como debe estar preparado tu Role

Tu modelo Role debe implementar:

```php
Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions
```

Ejemplo:

```php
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRolePermissions;
use Ronu\RestGenericClass\Core\Traits\HasReadableRolePermissions;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole implements ProvidesRolePermissions
{
    use HasReadableRolePermissions;
}
```

Ese trait sabe leer la relacion `enabled_permissions` cuando existe.

---

## 9. Flujo completo con RGC activado

```text
1. Usuario entra con Google.
2. laravel-federated-auth valida Google.
3. Se obtiene ExternalIdentity.
4. Se resuelve usuario local.
5. Se emite JWT local.
6. AuthResponseFormatterInterface formatea la respuesta.
7. PermissionPayloadResolverInterface intenta anexar permisos.
8. RestGenericPermissionPayloadResolver verifica:
      - RGC esta instalado?
      - User implementa ProvidesRoles?
      - User tiene permissionsPayload()?
9. Si todo esta bien, anexa permisos.
10. Si algo falta, no rompe el login; simplemente omite permisos.
```

Esto es muy importante:

```text
Un fallo de permisos opcionales no debe bloquear la autenticacion.
```

---

## 10. Respuesta estilo RGC

Con `RestGenericAuthResponseFormatter`, la respuesta queda asi:

```json
{
  "ok": true,
  "data": {
    "user": {
      "id": 25,
      "email": "client@example.com",
      "user_type": "Client",
      "auth_identifier": 25
    },
    "auth": {
      "token": "jwt-token",
      "access_token": "jwt-token",
      "token_type": "bearer",
      "expires_in": 60
    },
    "federated": {
      "provider": "google",
      "was_provisioned": false,
      "was_linked": false
    },
    "permissions": {
      "count": 3,
      "permissions": [
        {
          "id": 1,
          "name": "appointments.index",
          "module": "medical",
          "guard": "api"
        }
      ]
    }
  },
  "meta": {
    "provider": "google",
    "user_type": "Client",
    "channel": "mobile"
  }
}
```

Este formato es mas comodo para frontends porque separa:

```text
user        -> datos del usuario
auth        -> datos del token
federated   -> datos del login externo
permissions -> permisos efectivos
meta        -> contexto de la peticion
```

---

## 11. Que pasa si RGC no esta instalado

Si configuras accidentalmente el resolver RGC pero RGC no esta instalado, el detector lo evita.

Resultado:

```text
No se agregan permisos.
No se rompe el login.
```

El objetivo es mantener seguridad y disponibilidad.

---

## 12. Errores comunes

### 12.1. No aparecen permisos en la respuesta

Revisa:

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
```

Revisa bindings:

```php
PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
```

Revisa que el modelo User implemente:

```php
ProvidesRoles
```

Revisa que use:

```php
HasReadableUserPermissions
```

### 12.2. Error de contratos RGC

Si RGC dice que falta un contrato, normalmente es porque:

```text
User no implementa ProvidesRoles
Role no implementa ProvidesRolePermissions
la relacion de roles tiene otro nombre y no declaraste ROLES_RELATION
```

### 12.3. Login funciona pero permissions viene vacio

Esto puede ser correcto si el usuario no tiene roles o permisos.

Prueba el endpoint de RGC:

```http
GET /api/permissions
Authorization: Bearer <token>
```

Si ese endpoint no devuelve permisos, el problema esta en la configuracion de RGC/roles, no en la federated auth.

---

## 13. Seguridad para juniors

No hagas esto:

```text
Dar rol Admin porque alguien entro con Google.
```

Google confirma identidad, no autoridad dentro de tu negocio.

Correcto:

```text
Google/Facebook/Apple -> Client
Keycloak/OIDC empresarial -> roles mapeables con allowlist
```

Ejemplo de allowlist mental:

```php
[
    'keycloak-admin' => 'Admin',
    'keycloak-vet' => 'Veterinarian',
    'keycloak-client' => 'Client',
]
```

---

## 14. Como probarlo manualmente

### Caso A: sin permisos

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=false
```

Login esperado:

```text
Devuelve user + token.
No devuelve permissions.
```

### Caso B: con permisos RGC

```env
FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true
FEDERATED_AUTH_RGC_ENABLED=true
```

Bindings:

```php
PermissionPayloadResolverInterface::class => RestGenericPermissionPayloadResolver::class,
AuthResponseFormatterInterface::class => RestGenericAuthResponseFormatter::class,
```

Login esperado:

```text
Devuelve ok/data/meta.
Dentro de data devuelve user, auth, federated y permissions.
```

---

## 15. Checklist junior

- [ ] Instale `ronu/laravel-federated-auth`.
- [ ] Instale `ronu/rest-generic-class` solo si quiero integracion de permisos/respuesta.
- [ ] Configure Google/Facebook/Apple/Keycloak.
- [ ] Configure `FEDERATED_AUTH_RESPONSE_INCLUDE_PERMISSIONS=true` si quiero permisos en login.
- [ ] Cambie `PermissionPayloadResolverInterface` al resolver RGC.
- [ ] Cambie `AuthResponseFormatterInterface` al formatter RGC si quiero `ok/data/meta`.
- [ ] Mi User implementa `ProvidesRoles`.
- [ ] Mi Role implementa `ProvidesRolePermissions`.
- [ ] Probe `/api/permissions` con el token local.
- [ ] Verifique que Admin no se crea automaticamente desde Google/Facebook/Apple.

---

## 16. Resumen final

La integracion correcta es opcional:

```text
Si RGC existe, enriquecemos.
Si RGC no existe, autenticamos igual.
```

La frase clave:

```text
No acoples la puerta de entrada a la biblioteca de CRUD.
Conecta ambas mediante contratos pequenos.
```
