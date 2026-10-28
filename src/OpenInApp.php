<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_values;
use function explode;
use function gmdate;
use function http_build_query;
use function implode;
use function in_array;
use function is_object;
use function is_string;
use function parse_str;
use function parse_url;
use function rawurlencode;
use function redirect;
use function response;
use function str_contains;
use function str_starts_with;
use function strrchr;
use function substr;
use function time;
use function trim;

use const PHP_URL_HOST;
use const PHP_URL_PATH;

/**
 * Links in der App oeffnen (#22): Wird eine Seite, die eine App selbst zeigen kann (Apps.php, Feld open), direkt von
 * aussen aufgerufen - aus einer Mail, einem Chat, einem Lesezeichen -, bietet die Fusszeile an, sie in der App zu
 * oeffnen. Die Wahl des Benutzers ("immer", "nie", "nachfragen" je Bereich) steht in einem Cookie dieses Browsers,
 * der Server speichert dafuer nichts. Der Verwalter kann einzelne Bereiche ganz abschalten.
 */
trait OpenInApp
{
    // Moduleinstellung: abgeschaltete Bereiche (Apps::AREAS, mit Komma). Leer = alle an.
    private const string OPEN_AREAS_OFF_SETTING = 'open_areas_off';

    // Cookie dieses Browsers: on=0|1, app=<Kennung>, je Bereich always|never|ask - ohne Cookie: an, ueberall nachfragen.
    private const string OPEN_COOKIE         = 'api4webtrees_open';
    private const int    OPEN_COOKIE_SECONDS = 2 * 365 * 24 * 3600;
    private const array  OPEN_MODES          = ['ask', 'always', 'never'];

    // Sitzung: schon einmal gefragt - der Dialog kommt je Sitzung nur einmal, auch nicht beim Neuladen.
    private const string OPEN_ASKED_SESSION = 'api4webtrees_open_asked';

    // Zurueck aus Chrome, weil die App fehlt (intent://, S.browser_fallback_url): die Seite bleibt im Browser.
    private const string OPEN_STAY = 'open-stay';

    // Rueckweg in die App nach dem Einrichten von dort aus (?open-in=<Kennung>)
    private const string OPEN_DONE_PATH = 'open-in?result=';

    /**
     * Die Bereiche mit ihrem Namen fuer die Seiten, in der Sprache des Benutzers.
     *
     * @return array<string,string>
     */
    private static function openAreaNames(): array
    {
        return [
            'individual' => I18N::translate('Personen'),
            'family'     => I18N::translate('Familien'),
            'media'      => I18N::translate('Medien'),
            'chart'      => I18N::translate('Diagramme'),
        ];
    }

    /**
     * Der Bereich der aufgerufenen Seite - null fuer jede andere. Am Kurznamen der Route erkannt (webtrees 2.2 hat sie
     * unter RequestHandlers, 2.3 unter Controllers); Diagramme an ihrem Modul.
     */
    private static function openArea(ServerRequestInterface $request): string|null
    {
        $route = $request->getAttribute('route');

        if (!is_object($route)) {
            return null;
        }

        $name  = is_string($route->name ?? null) ? $route->name : '';
        $short = str_contains($name, '\\') ? substr((string) strrchr($name, '\\'), 1) : $name;

        return match (true) {
            $short === 'IndividualPage'                       => 'individual',
            $short === 'FamilyPage'                           => 'family',
            $short === 'MediaPage'                            => 'media',
            ($route->handler ?? null) instanceof ModuleChartInterface => 'chart',
            default                                           => null,
        };
    }

    /**
     * Direkt von aussen geoeffnet, nicht innerhalb von webtrees weitergeklickt? Moderne Browser sagen es in
     * Sec-Fetch-Site (none: Lesezeichen, eingetippt, aus einer anderen App; cross-site: von einer fremden Seite),
     * aeltere nur ueber den Referer.
     */
    private static function cameFromOutside(ServerRequestInterface $request): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        $site = $request->getHeaderLine('Sec-Fetch-Site');

        if ($site !== '') {
            return $site === 'none' || $site === 'cross-site';
        }

        $referer = $request->getHeaderLine('Referer');

        return $referer === '' || parse_url($referer, PHP_URL_HOST) !== $request->getUri()->getHost();
    }

    /**
     * Die vom Verwalter abgeschalteten Bereiche.
     *
     * @return list<string>
     */
    private function openAreasOff(): array
    {
        $setting = trim($this->getPreference(self::OPEN_AREAS_OFF_SETTING));

        return $setting === '' ? [] : explode(',', $setting);
    }

    /**
     * Die Wahl dieses Browsers aus dem Cookie, geprueft und mit den Standards ergaenzt.
     *
     * @return array{on:bool,app:string,modes:array<string,string>}
     */
    private static function openChoice(ServerRequestInterface $request): array
    {
        $raw    = (string) ($request->getCookieParams()[self::OPEN_COOKIE] ?? '');
        $stored = [];
        parse_str($raw, $stored);

        $modes = [];

        foreach (Apps::AREAS as $area) {
            $mode         = $stored[$area] ?? 'ask';
            $modes[$area] = in_array($mode, self::OPEN_MODES, true) ? $mode : 'ask';
        }

        return [
            'on'    => ($stored['on'] ?? '1') !== '0',
            'app'   => is_string($stored['app'] ?? null) ? $stored['app'] : '',
            'modes' => $modes,
        ];
    }

    /**
     * Die Wahl als Cookie in die Antwort - vom Server gesetzt, damit Safari es nicht nach 7 Tagen loescht.
     *
     * @param array{on:bool,app:string,modes:array<string,string>} $choice
     */
    private static function withOpenChoice(ResponseInterface $response, ServerRequestInterface $request, array $choice): ResponseInterface
    {
        $base_url = Validator::attributes($request)->string('base_url');
        $path     = (string) parse_url($base_url, PHP_URL_PATH);
        $value    = http_build_query(['on' => $choice['on'] ? '1' : '0', 'app' => $choice['app']] + $choice['modes']);

        $cookie = self::OPEN_COOKIE . '=' . rawurlencode($value)
            . '; Path=' . ($path === '' ? '/' : $path)
            . '; Max-Age=' . self::OPEN_COOKIE_SECONDS
            . '; Expires=' . gmdate('D, d M Y H:i:s', time() + self::OPEN_COOKIE_SECONDS) . ' GMT'
            . '; SameSite=Lax; HttpOnly'
            . (str_starts_with($base_url, 'https://') ? '; Secure' : '');

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Die Apps, die diese Seite auf diesem Geraet oeffnen koennen: freigeschaltet, fuer das Geraet, mit dem Bereich.
     *
     * @return list<array<string,mixed>>
     */
    private function openApps(string $device, string $area): array
    {
        if (in_array($area, $this->openAreasOff(), true)) {
            return [];
        }

        return array_values(array_filter(
            Apps::matching($this->apps(), $device),
            static fn (array $app): bool => in_array($area, $app['open'], true)
        ));
    }

    /**
     * Die Apps fuer dieses Geraet, die ueberhaupt Seiten oeffnen - fuer die Einstellungen auf der Seite "App".
     *
     * @return list<array<string,mixed>>
     */
    private function openAppsForDevice(string $device): array
    {
        return array_values(array_filter(
            Apps::matching($this->apps(), $device),
            fn (array $app): bool => array_diff($app['open'], $this->openAreasOff()) !== []
        ));
    }

    /**
     * Der Link, der die Seite in der App oeffnet. Android mit Paketname: intent:// - Chrome oeffnet die App oder,
     * ist sie nicht installiert, den Store, ohne Fehlermeldung.
     *
     * @param array<string,mixed> $app
     */
    private static function openLink(array $app, string $device, string $page_url): string
    {
        $link    = $app['scheme'] . '://open?url=' . rawurlencode($page_url);
        $package = $app['package'][$device] ?? '';

        if ($package === '') {
            return $link;
        }

        return 'intent://open?url=' . rawurlencode($page_url) . '#Intent;scheme=' . $app['scheme'] . ';package=' . $package
            . ';S.browser_fallback_url=' . rawurlencode(Apps::download($app, $device)) . ';end';
    }

    /**
     * Android, "immer": gleich weiter in die App, per Umleitung statt per Skript - Chrome oeffnet eine App nur aus
     * einem Klick oder einer Umleitung heraus. Ist sie nicht installiert, kommt Chrome mit ?OPEN_STAY auf die Seite
     * zurueck, die dann bleibt. null = nichts zu tun.
     */
    private function openRedirect(ServerRequestInterface $request): ResponseInterface|null
    {
        $area = self::openArea($request);
        $tree = Validator::attributes($request)->treeOptional();

        if ($area === null || $tree === null || !$this->treeEnabled($tree) || !self::cameFromOutside($request)
            || Validator::queryParams($request)->string(self::OPEN_STAY, '') !== '' || Session::has(self::OPEN_ASKED_SESSION)) {
            return null;
        }

        $choice = self::openChoice($request);
        $device = self::requestDevice($request);

        if (!$choice['on'] || $choice['modes'][$area] !== 'always') {
            return null;
        }

        foreach ($this->openApps($device, $area) as $app) {
            if ($app['id'] === $choice['app'] && ($app['package'][$device] ?? '') !== '') {
                Session::put(self::OPEN_ASKED_SESSION, true);
                $page = (string) $request->getUri();
                $stay = $page . (str_contains($page, '?') ? '&' : '?') . self::OPEN_STAY . '=1';

                return redirect('intent://open?url=' . rawurlencode($page) . '#Intent;scheme=' . $app['scheme'] . ';package='
                    . $app['package'][$device] . ';S.browser_fallback_url=' . rawurlencode($stay) . ';end');
            }
        }

        return null;
    }

    /**
     * Daten fuer den Dialog in der Fusszeile - null, wenn nichts anzubieten ist.
     *
     * @return array<string,mixed>|null
     */
    private function openOffer(ServerRequestInterface $request): array|null
    {
        $area = self::openArea($request);

        if ($area === null || !self::cameFromOutside($request) || Session::has(self::OPEN_ASKED_SESSION)
            || Validator::queryParams($request)->string(self::OPEN_STAY, '') !== '') {
            return null;
        }

        $choice = self::openChoice($request);
        $mode   = $choice['modes'][$area];

        if (!$choice['on'] || $mode === 'never') {
            return null;
        }

        $device = self::requestDevice($request);
        $apps   = $this->openApps($device, $area);

        if ($apps === []) {
            return null;
        }

        // Immer: nur mit einer gewaehlten App, die es hier noch gibt - sonst wieder fragen.
        $always = $mode === 'always' ? array_values(array_filter($apps, static fn (array $app): bool => $app['id'] === $choice['app']))[0] ?? null : null;
        $page   = (string) $request->getUri();

        Session::put(self::OPEN_ASKED_SESSION, true);

        $offers = [];

        foreach ($always === null ? $apps : [$always] as $app) {
            $offers[] = [
                'id'     => $app['id'],
                'name'   => $app['name'],
                'link'   => self::openLink($app, $device, $page),
                'store'  => Apps::download($app, $device),
                'badge'  => $this->badgeUrls([$app])[$app['id']][$device] ?? '',
                'icon'   => $this->iconUrls([$app])[$app['id']] ?? '',
                'intent' => ($app['package'][$device] ?? '') !== '',
            ];
        }

        return [
            'offers'     => $offers,
            'always'     => $always !== null,
            'choice_url' => $this->actionUrl('OpenChoice', null),
            'app_url'    => $this->actionUrl('App', Validator::attributes($request)->treeOptional()?->name()),
        ];
    }

    /**
     * "Auswahl merken" im Dialog: app=<Kennung> - alle Bereiche immer in dieser App; never=1 - nie mehr fragen.
     */
    public function postOpenChoiceAction(ServerRequestInterface $request): ResponseInterface
    {
        $body   = Validator::parsedBody($request);
        $choice = self::openChoice($request);

        $app    = $body->string('app', '');

        if ($body->string('never', '') === '1') {
            $choice['on'] = false;
        } elseif (array_filter($this->apps(), static fn (array $a): bool => $a['id'] === $app) !== []) {
            $choice = ['on' => true, 'app' => $app, 'modes' => array_fill_keys(Apps::AREAS, 'always')];
        }

        return self::withOpenChoice(response(['ok' => true]), $request, $choice);
    }

    /**
     * Einstellungen auf der Seite "App": an/aus, die App und je Bereich immer/nie/nachfragen. return=<Kennung>: nach
     * dem Einrichten aus der App zurueck in die App.
     */
    public function postOpenSettingsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body   = Validator::parsedBody($request);
        $choice = self::openChoice($request);
        $tree   = Validator::attributes($request)->treeOptional();
        $back   = $this->actionUrl('App', $tree?->name());

        $choice['on'] = $body->string('on', $choice['on'] ? '1' : '0') === '1';

        $app = $body->string('app', $choice['app']);

        if (array_filter($this->apps(), static fn (array $a): bool => $a['id'] === $app) !== []) {
            $choice['app'] = $app;
        }

        foreach (Apps::AREAS as $area) {
            $mode = $body->string('mode-' . $area, $choice['modes'][$area]);
            $choice['modes'][$area] = in_array($mode, self::OPEN_MODES, true) ? $mode : 'ask';
        }

        // Aus der App eingerichtet: alles immer in dieser App, dann zurueck auf die Seite mit dem Knopf zur App.
        if ($body->string('all', '') === 'always') {
            $choice['modes'] = array_fill_keys(Apps::AREAS, 'always');
            $back           .= (str_contains($back, '?') ? '&' : '?') . http_build_query(['open-done' => $choice['app']]);
        }

        FlashMessages::addMessage(I18N::translate('Die Einstellungen wurden gespeichert.'), 'success');

        return self::withOpenChoice(redirect($back), $request, $choice);
    }

    /**
     * Daten fuer den Abschnitt "Links in der App oeffnen" auf der Seite "App" - null, wenn keine App auf diesem
     * Geraet Seiten oeffnen kann.
     *
     * @return array<string,mixed>|null
     */
    private function openSettings(ServerRequestInterface $request, string $device, string|null $tree): array|null
    {
        $apps = $this->openAppsForDevice($device);

        if ($apps === []) {
            return null;
        }

        $choice = self::openChoice($request);
        $areas  = [];

        foreach (self::openAreaNames() as $area => $name) {
            if (!in_array($area, $this->openAreasOff(), true) && array_filter($apps, static fn (array $app): bool => in_array($area, $app['open'], true)) !== []) {
                $areas[$area] = $name;
            }
        }

        $query    = Validator::queryParams($request);
        $open_in  = array_values(array_filter($apps, static fn (array $app): bool => $app['id'] === $query->string('open-in', '')))[0] ?? null;
        $done     = array_values(array_filter($apps, static fn (array $app): bool => $app['id'] === $query->string('open-done', '')))[0] ?? null;

        return [
            'apps'     => $apps,
            'choice'   => $choice,
            'areas'    => $areas,
            'save_url' => $this->actionUrl('OpenSettings', $tree),
            'open_in'  => $open_in,
            'done_url' => $done === null ? '' : $done['scheme'] . '://' . self::OPEN_DONE_PATH . 'on',
            'done_app' => $done,
        ];
    }
}
