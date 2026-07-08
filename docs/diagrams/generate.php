<?php

/**
 * Minimal, deterministic UML sequence-diagram SVG generator.
 * Produces GitHub-friendly SVGs (opaque light card, dark text) that stay
 * legible in both light and dark README themes.
 *
 * These diagrams are the source of truth for the README auth flows and are
 * kept in sync with Services\FederatedAuthBroker. Regenerate after changing
 * the pipeline:
 *
 *     php docs/diagrams/generate.php docs/diagrams
 */

const COL_STEP = 176;
const LEFT_PAD = 92;
const BOX_W    = 150;
const BOX_H    = 48;
const TITLE_H  = 58;
const HEAD_TOP = TITLE_H + 8;
const LIFE_TOP = HEAD_TOP + BOX_H;
const CONTENT  = LIFE_TOP + 34;
const ROW_MSG  = 36;

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function centerX(int $i): int
{
    return LEFT_PAD + $i * COL_STEP;
}

/**
 * @param array<string,array{name:string,role:string,color:string}> $participants
 * @param list<array<string,mixed>> $steps
 */
function render(string $title, array $participants, array $steps): string
{
    $ids  = array_keys($participants);
    $idx  = array_flip($ids);
    $last = count($ids) - 1;
    $width = centerX($last) + BOX_W / 2 + 40;

    // ---- first pass: compute Y for each step + total height ----
    $y = CONTENT;
    foreach ($steps as $k => $step) {
        switch ($step['kind']) {
            case 'phase':
                $y += 14;
                $steps[$k]['_y'] = $y;
                $y += 30;
                break;
            case 'self':
                $steps[$k]['_y'] = $y;
                $y += 42;
                break;
            case 'note':
                $lines = $step['lines'];
                $h = 14 * count($lines) + 16;
                $steps[$k]['_y'] = $y;
                $steps[$k]['_h'] = $h;
                $y += $h + 26;
                break;
            default: // msg
                $steps[$k]['_y'] = $y;
                $y += ROW_MSG;
        }
    }
    $lifeBottom = $y + 6;
    $height = $lifeBottom + BOX_H + 30;

    // ---- SVG head ----
    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" '
        .'viewBox="0 0 '.$width.' '.$height.'" font-family="Segoe UI, Roboto, Helvetica, Arial, sans-serif">';
    $svg[] = '<defs>'
        .'<marker id="call" markerWidth="11" markerHeight="11" refX="8" refY="4" orient="auto">'
        .'<path d="M0,0 L9,4 L0,8 z" fill="#2b3a4a"/></marker>'
        .'<marker id="ret" markerWidth="12" markerHeight="12" refX="8" refY="4" orient="auto">'
        .'<path d="M0,0 L9,4 L0,8" fill="none" stroke="#78868f" stroke-width="1.2"/></marker>'
        .'</defs>';
    $svg[] = '<rect x="0" y="0" width="'.$width.'" height="'.$height.'" rx="10" fill="#ffffff" stroke="#d7dee4"/>';
    $svg[] = '<text x="'.($width / 2).'" y="34" text-anchor="middle" font-size="20" font-weight="700" fill="#1f2933">'.e($title).'</text>';

    // ---- lifelines + participant boxes (top & bottom) ----
    foreach ($ids as $i => $id) {
        $cx = centerX($i);
        $svg[] = '<line x1="'.$cx.'" y1="'.LIFE_TOP.'" x2="'.$cx.'" y2="'.$lifeBottom.'" stroke="#c4ccd4" stroke-width="1.4" stroke-dasharray="2 5"/>';
        foreach ([HEAD_TOP, $lifeBottom] as $by) {
            $svg[] = box($cx, $by, $participants[$id]);
        }
    }

    // ---- second pass: draw steps ----
    foreach ($steps as $step) {
        $y = $step['_y'];
        switch ($step['kind']) {
            case 'phase':
                $svg[] = phase($width, $y, $step['text']);
                break;
            case 'self':
                $svg[] = selfMsg(centerX($idx[$step['at']]), $y, $step['text']);
                break;
            case 'note':
                $svg[] = note(centerX($idx[$step['at']]), $y, $step['_h'], $step['lines']);
                break;
            default:
                $svg[] = message(
                    centerX($idx[$step['from']]),
                    centerX($idx[$step['to']]),
                    $y,
                    $step['text'],
                    ($step['style'] ?? 'call') === 'ret'
                );
        }
    }

    $svg[] = '</svg>';

    return implode("\n", $svg)."\n";
}

function box(int $cx, int $topY, array $p): string
{
    $x = $cx - BOX_W / 2;

    return '<g>'
        .'<rect x="'.$x.'" y="'.$topY.'" width="'.BOX_W.'" height="'.BOX_H.'" rx="7" fill="'.$p['color'].'"/>'
        .'<text x="'.$cx.'" y="'.($topY + 20).'" text-anchor="middle" font-size="13" font-weight="700" fill="#ffffff">'.e($p['name']).'</text>'
        .'<text x="'.$cx.'" y="'.($topY + 36).'" text-anchor="middle" font-size="10" fill="#ffffff" opacity="0.82">'.e($p['role']).'</text>'
        .'</g>';
}

function phase(int $width, int $y, string $text): string
{
    $w = strlen($text) * 7.4 + 34;
    $x = ($width - $w) / 2;

    return '<g>'
        .'<line x1="24" y1="'.$y.'" x2="'.($width - 24).'" y2="'.$y.'" stroke="#e2e8ee" stroke-width="1"/>'
        .'<rect x="'.$x.'" y="'.($y - 13).'" width="'.$w.'" height="26" rx="13" fill="#eef2f6" stroke="#cfd8e0"/>'
        .'<text x="'.($width / 2).'" y="'.($y + 4).'" text-anchor="middle" font-size="11.5" font-weight="700" letter-spacing="0.6" fill="#516170">'.e(strtoupper($text)).'</text>'
        .'</g>';
}

function message(int $x1, int $x2, int $y, string $text, bool $ret): string
{
    $stroke = $ret ? '#78868f' : '#2b3a4a';
    $dash   = $ret ? ' stroke-dasharray="6 4"' : '';
    $marker = $ret ? 'ret' : 'call';
    $mid    = ($x1 + $x2) / 2;
    $tw     = strlen($text) * 6.35 + 8;

    return '<g>'
        .'<rect x="'.($mid - $tw / 2).'" y="'.($y - 21).'" width="'.$tw.'" height="15" fill="#ffffff"/>'
        .'<text x="'.$mid.'" y="'.($y - 9).'" text-anchor="middle" font-size="12" fill="#2c3742">'.e($text).'</text>'
        .'<line x1="'.$x1.'" y1="'.$y.'" x2="'.$x2.'" y2="'.$y.'" stroke="'.$stroke.'" stroke-width="1.5"'.$dash.' marker-end="url(#'.$marker.')"/>'
        .'</g>';
}

function selfMsg(int $cx, int $y, string $text): string
{
    $w = 46;

    return '<g>'
        .'<path d="M'.$cx.','.$y.' h'.$w.' v18 h-'.$w.'" fill="none" stroke="#2b3a4a" stroke-width="1.5" marker-end="url(#call)"/>'
        .'<text x="'.($cx + $w + 8).'" y="'.($y + 5).'" font-size="12" fill="#2c3742">'.e($text).'</text>'
        .'</g>';
}

function note(int $cx, int $y, int $h, array $lines): string
{
    $w = 0;
    foreach ($lines as $l) {
        $w = max($w, strlen($l) * 6.2);
    }
    $w += 24;
    $x = min($cx + 14, 100000);
    $g = ['<g>'];
    $g[] = '<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="4" fill="#fff6e5" stroke="#efc667"/>';
    $ty = $y + 17;
    foreach ($lines as $l) {
        $g[] = '<text x="'.($x + 12).'" y="'.$ty.'" font-size="11" fill="#7a5b12">'.e($l).'</text>';
        $ty += 14;
    }
    $g[] = '</g>';

    return implode('', $g);
}

// ---------------------------------------------------------------------------
$P = [
    'client'  => ['name' => 'Client',            'role' => 'Browser / Mobile',       'color' => '#b45309'],
    'ctrl'    => ['name' => 'Controller',        'role' => 'FederatedAuthController', 'color' => '#1d4ed8'],
    'broker'  => ['name' => 'Broker',            'role' => 'FederatedAuthBroker',     'color' => '#6d28d9'],
    'state'   => ['name' => 'State Store',       'role' => 'OAuthStateStore',         'color' => '#0f766e'],
    'adapter' => ['name' => 'Adapter',           'role' => 'ProviderAdapter',         'color' => '#0f766e'],
    'idp'     => ['name' => 'Provider',          'role' => 'External IdP',            'color' => '#334155'],
    'repo'    => ['name' => 'Link Repo',         'role' => 'IdentityLinkRepository',  'color' => '#047857'],
    'resolve' => ['name' => 'Resolver',          'role' => 'UserResolver',            'color' => '#047857'],
    'provis'  => ['name' => 'Provisioner',       'role' => 'UserProvisioner',         'color' => '#b91c1c'],
    'status'  => ['name' => 'Status Checker',    'role' => 'UserStatusChecker',       'color' => '#047857'],
    'roles'   => ['name' => 'Role Mapper',       'role' => 'RoleMapper',              'color' => '#047857'],
    'token'   => ['name' => 'Token Issuer',      'role' => 'TokenIssuer',             'color' => '#047857'],
    'fmt'     => ['name' => 'Formatter',         'role' => 'AuthResponseFormatter',   'color' => '#1d4ed8'],
];

function pick(array $P, array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $P[$k];
    }

    return $out;
}

// ============================ LOGIN ========================================
$loginParts = pick($P, ['client', 'ctrl', 'broker', 'state', 'adapter', 'idp', 'repo', 'resolve', 'status', 'roles', 'token', 'fmt']);

$login = [
    ['kind' => 'phase', 'text' => '1 · Authorization redirect'],
    ['kind' => 'msg', 'from' => 'client',  'to' => 'ctrl',    'text' => 'GET /{provider}/redirect  (tenant_id, user_type, channel)'],
    ['kind' => 'msg', 'from' => 'ctrl',    'to' => 'broker',  'text' => 'redirectUrl(provider, AuthContext)'],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'adapter', 'text' => 'redirectUrl(context)'],
    ['kind' => 'msg', 'from' => 'adapter', 'to' => 'state',   'text' => 'create(provider, context)'],
    ['kind' => 'msg', 'from' => 'state',   'to' => 'adapter', 'text' => 'OAuthAuthorizationState (one-time state, nonce, PKCE)', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'adapter', 'to' => 'broker',  'text' => 'provider authorize URL (stateless)', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'ctrl',    'text' => 'URL', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'ctrl',    'to' => 'client',  'text' => '302 Redirect  →  provider', 'style' => 'ret'],

    ['kind' => 'phase', 'text' => '2 · Provider authentication'],
    ['kind' => 'msg', 'from' => 'client', 'to' => 'idp',    'text' => 'authenticate + consent'],
    ['kind' => 'msg', 'from' => 'idp',    'to' => 'client', 'text' => '302  →  /callback?code&state', 'style' => 'ret'],

    ['kind' => 'phase', 'text' => '3 · Callback & local login'],
    ['kind' => 'msg', 'from' => 'client',  'to' => 'ctrl',    'text' => 'GET /{provider}/callback?code&state'],
    ['kind' => 'msg', 'from' => 'ctrl',    'to' => 'broker',  'text' => 'loginFromCallback(provider, context)'],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'state',   'text' => 'consume(provider, state, request)'],
    ['kind' => 'msg', 'from' => 'state',   'to' => 'broker',  'text' => 'state OK  →  restore tenant/user_type/channel/guard', 'style' => 'ret'],
    ['kind' => 'note', 'at' => 'broker', 'lines' => ['One-time state consumed: replay rejected;', 'app context restored from the stored transaction.']],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'adapter', 'text' => 'userFromCallback(context)'],
    ['kind' => 'msg', 'from' => 'adapter', 'to' => 'idp',     'text' => 'exchange code / fetch profile + claims'],
    ['kind' => 'msg', 'from' => 'idp',     'to' => 'adapter', 'text' => 'profile + claims', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'adapter', 'to' => 'broker',  'text' => 'ExternalIdentity (normalized)', 'style' => 'ret'],
    ['kind' => 'self', 'at' => 'broker', 'text' => 'validateIdentity()  ·  email / user_type / admin guard'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'repo',    'text' => 'findByProviderIdentity(provider, providerUserId)'],
    ['kind' => 'msg', 'from' => 'repo',   'to' => 'broker',  'text' => 'LinkedIdentity  (found)', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'resolve', 'text' => 'resolveById(userId)'],
    ['kind' => 'msg', 'from' => 'resolve','to' => 'broker',  'text' => 'local user', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'status',  'text' => 'ensureCanLogin(user)  ·  blocks disabled users'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'repo',    'text' => 'touch(link, identity)  ·  last_login_at, claims'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'roles',   'text' => 'sync(user, identity)'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'token',   'text' => 'issue(user, context)'],
    ['kind' => 'msg', 'from' => 'token',  'to' => 'broker',  'text' => 'tokens + metadata', 'style' => 'ret'],
    ['kind' => 'self', 'at' => 'broker', 'text' => 'event ExternalLoginSucceeded'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'ctrl',    'text' => 'AuthResult (was_provisioned=false, was_linked=false)', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'ctrl',   'to' => 'fmt',     'text' => 'format(result, context)'],
    ['kind' => 'msg', 'from' => 'fmt',    'to' => 'ctrl',    'text' => 'response payload', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'ctrl',   'to' => 'client',  'text' => '200 JSON  { access_token, token_type, user }', 'style' => 'ret'],
];

// ============================ REGISTER =====================================
$regParts = pick($P, ['client', 'ctrl', 'broker', 'state', 'adapter', 'idp', 'repo', 'resolve', 'provis', 'status', 'roles', 'token', 'fmt']);

$register = [
    ['kind' => 'phase', 'text' => '1 · Redirect & provider authentication'],
    ['kind' => 'msg', 'from' => 'client',  'to' => 'ctrl',    'text' => 'GET /{provider}/redirect  →  302 to provider  →  consent'],
    ['kind' => 'msg', 'from' => 'ctrl',    'to' => 'broker',  'text' => 'redirectUrl(provider, context)  ·  state created'],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'state',   'text' => 'create(provider, context)'],
    ['kind' => 'msg', 'from' => 'state',   'to' => 'broker',  'text' => 'one-time state, nonce, PKCE', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'idp',     'to' => 'client',  'text' => '302  →  /callback?code&state', 'style' => 'ret'],

    ['kind' => 'phase', 'text' => '2 · Callback, provisioning & first login'],
    ['kind' => 'msg', 'from' => 'client',  'to' => 'ctrl',    'text' => 'GET /{provider}/callback?code&state'],
    ['kind' => 'msg', 'from' => 'ctrl',    'to' => 'broker',  'text' => 'loginFromCallback(provider, context)'],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'state',   'text' => 'consume(provider, state, request)'],
    ['kind' => 'msg', 'from' => 'state',   'to' => 'broker',  'text' => 'state OK  →  restore app context', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'adapter', 'text' => 'userFromCallback(context)'],
    ['kind' => 'msg', 'from' => 'adapter', 'to' => 'idp',     'text' => 'exchange code / verify id_token (nonce)'],
    ['kind' => 'msg', 'from' => 'idp',     'to' => 'adapter', 'text' => 'profile + verified claims', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'adapter', 'to' => 'broker',  'text' => 'ExternalIdentity (normalized)', 'style' => 'ret'],
    ['kind' => 'self', 'at' => 'broker', 'text' => 'validateIdentity()  ·  require_email / allowed_user_types / prevent_admin_auto_provision'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'repo',    'text' => 'findByProviderIdentity(provider, providerUserId)'],
    ['kind' => 'msg', 'from' => 'repo',   'to' => 'broker',  'text' => 'null  →  identity not linked', 'style' => 'ret'],
    ['kind' => 'note', 'at' => 'broker', 'lines' => ['allow_email_linking only (opt-in) and requires a', 'verified provider email before matching by email.']],
    ['kind' => 'msg', 'from' => 'broker',  'to' => 'resolve', 'text' => 'resolveByEmail(identity)  ·  optional, verified only'],
    ['kind' => 'msg', 'from' => 'resolve', 'to' => 'broker',  'text' => 'null  →  no existing user', 'style' => 'ret'],
    ['kind' => 'note', 'at' => 'broker', 'lines' => ['auto_provision must be enabled, else', 'UserProvisioningNotConfiguredException.']],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'provis',  'text' => 'provision(identity, context)  ·  create local user'],
    ['kind' => 'msg', 'from' => 'provis', 'to' => 'broker',  'text' => 'new Authenticatable', 'style' => 'ret'],
    ['kind' => 'self', 'at' => 'broker', 'text' => 'event ExternalUserProvisioned'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'status',  'text' => 'ensureCanLogin(user)'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'repo',    'text' => 'create(userId, identity)  ·  link tenant+provider+sub'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'roles',   'text' => 'sync(user, identity)'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'token',   'text' => 'issue(user, context)'],
    ['kind' => 'msg', 'from' => 'token',  'to' => 'broker',  'text' => 'tokens + metadata', 'style' => 'ret'],
    ['kind' => 'self', 'at' => 'broker', 'text' => 'event ExternalLoginSucceeded'],
    ['kind' => 'msg', 'from' => 'broker', 'to' => 'ctrl',    'text' => 'AuthResult (was_provisioned=true, was_linked=true)', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'ctrl',   'to' => 'fmt',     'text' => 'format(result, context)'],
    ['kind' => 'msg', 'from' => 'fmt',    'to' => 'ctrl',    'text' => 'response payload', 'style' => 'ret'],
    ['kind' => 'msg', 'from' => 'ctrl',   'to' => 'client',  'text' => '200 JSON  { access_token, user, was_provisioned:true }', 'style' => 'ret'],
];

$outDir = $argv[1] ?? '.';
file_put_contents($outDir.'/federated-login-sequence.svg', render('Federated Login — returning user (existing identity)', $loginParts, $login));
file_put_contents($outDir.'/federated-register-sequence.svg', render('Federated Registration — first-time user (auto-provision)', $regParts, $register));
echo "done\n";
