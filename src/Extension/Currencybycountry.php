<?php
/**
 * @package    Plg_Pcv_Currencybycountry
 * @license    GNU General Public License version 3 or later
 */

namespace YourVendor\Plugin\Pcv\Currencybycountry\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;

\defined('_JEXEC') or die;

/**
 * Automatski predlaže ili prebacuje valutu na osnovu zemlje unijete u
 * Billing adresi tokom checkout-a (event: onPCVonCheckoutAfterAddress,
 * okida se svaki put kad se checkout stranica renderuje NAKON što je
 * saveaddress() kontroler snimio adresu i redirektovao nazad).
 *
 * Tri moda rada (podešava se u plugin parametrima):
 * - off:     isključeno
 * - suggest: prikazuje banner sa ponudom da se valuta promijeni (ne dira sesiju)
 * - auto:    tiho prebacuje valutu + redirect da se cijene prekalkulišu
 *
 * @since 0.1.0
 */
final class Currencybycountry extends CMSPlugin implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /** @var bool */
    protected $autoloadLanguage = true;

    /**
     * Guard protiv duplog dodavanja banner-a ako se event iz nekog razloga
     * okine više puta u istom requestu (zapaženo u praksi - dva poziva istog
     * eventa). Static jer treba da preživi ako se plugin instancira više puta.
     *
     * @since 0.1.0
     */
    private static bool $bannerAdded = false;

    public function __construct($subject, array $config = [])
    {
        parent::__construct($subject, $config);

        // Phoca Cart koristi sopstveni event dispatcher (Phoca\PhocaCart\Dispatcher\Dispatcher)
        // koji ipak internо prosleđuje na Joomla core dispatcher - ali po iskustvu sa
        // pcp event grupom, eksplicitna registracija je pouzdanija nego oslanjanje na
        // automatsko method-name mapiranje.
        if ($subject instanceof \Joomla\Event\DispatcherInterface) {
            $subject->addListener(
                'onPCVonCheckoutAfterAddress',
                [$this, 'onPCVonCheckoutAfterAddress']
            );
        }
    }

    /**
     * @param  \Joomla\Event\Event  $event
     * @since  0.1.0
     */
    public function onPCVonCheckoutAfterAddress(\Joomla\Event\Event $event): void
    {
        $mode = (string) $this->params->get('mode', 'suggest');

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG start', 0, 'mode=' . $mode . ' eventClass=' . get_class($event));

        if ($mode === 'off') {
            return;
        }

        $billingCountryId = $this->getBillingCountryId();

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG billingCountryId', 0, 'id=' . $billingCountryId);

        if ($billingCountryId < 1) {
            return;
        }

        $countryCode = strtoupper(\PhocacartCountry::getCountryByCode2($billingCountryId));

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG countryCode', 0, 'code=' . $countryCode);

        if ($countryCode === '') {
            return;
        }

        $desiredCurrencyCode = $this->getMappedCurrencyCode($countryCode);

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG mappedCurrency', 0, 'currency=' . ($desiredCurrencyCode ?? 'NULL'));

        if ($desiredCurrencyCode === null) {
            // Nema mapiranja za tu zemlju - ne diramo ništa, ostaje default valuta.
            return;
        }

        $currentCurrency = \PhocacartCurrency::getCurrency();
        $currentCode     = strtoupper((string) ($currentCurrency->code ?? ''));

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG currentCode', 0, 'current=' . $currentCode . ' desired=' . $desiredCurrencyCode);

        if ($currentCode === $desiredCurrencyCode) {
            // Već je na ispravnoj valuti - nema šta da se radi.
            return;
        }

        $targetCurrency = $this->findCurrencyByCode($desiredCurrencyCode);

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG targetCurrency', 0,
            $targetCurrency === null ? 'NOT FOUND' : ('id=' . $targetCurrency->id . ' code=' . $targetCurrency->code));

        if ($targetCurrency === null) {
            // Zemlja je mapirana na valutu koja ne postoji (ili nije published)
            // u Phoca Cart Currency podešavanjima - logujemo i odustajemo.
            \PhocacartLog::add(2, 'Currency By Country - ERROR', 0,
                'Mapped currency "' . $desiredCurrencyCode . '" for country "' . $countryCode . '" not found or unpublished.');
            return;
        }

        if ($mode === 'auto') {
            \PhocacartCurrency::setCurrentCurrency((int) $targetCurrency->id);
            $this->getApplication()->redirect(Uri::getInstance()->toString());
            return;
        }

        // mode === 'suggest': banner sa ponudom, ne diramo sesiju automatski.
        // KLJUČNO: rezultat se prijavljuje preko addResult() (Joomla ResultAware
        // API), ne preko return-a iz metode - AfterAddress event implementira
        // ResultAware/ResultTypeStringAware, i na Joomla 5+ je addResult()
        // JEDINI ispravan način (setArgument('result',...) baca exception).
        $isResultAware = $event instanceof \Joomla\CMS\Event\Result\ResultAwareInterface;

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG isResultAware', 0,
            ($isResultAware ? 'YES' : 'NO - falling back to setArgument') . ' objectId=' . spl_object_id($this) . ' bannerAlreadyAdded=' . (self::$bannerAdded ? 'YES' : 'NO'));

        if (self::$bannerAdded) {
            // Već smo dodali banner jednom u ovom requestu - preskačemo da
            // izbjegnemo duplikat (bez obzira zašto se event okinuo dvaput).
            return;
        }

        $banner = $this->renderSuggestBanner($targetCurrency, $currentCurrency, \PhocacartCountry::getCountryById($billingCountryId));

        \PhocacartLog::add(1, 'CurrencyByCountry - DEBUG banner length', 0, (string) strlen($banner));

        if ($isResultAware) {
            $event->addResult($banner);
        } else {
            // Fallback za slučaj da event ipak nije ResultAware u ovom Joomla
            // okruženju - pokušavamo klasičan setArgument pristup kao safety net.
            $existing = $event->getArgument('result', []);
            $existing = \is_array($existing) ? $existing : [];
            $existing[] = $banner;
            $event->setArgument('result', $existing);
        }

        self::$bannerAdded = true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Dohvata ID zemlje iz Billing adrese - radi i za ulogovanog korisnika i
     * za gosta, na osnovu potvrđenih Phoca Cart core izvora (checkout.php
     * getData()/getDataGuest()).
     *
     * @since 0.1.0
     */
    private function getBillingCountryId(): int
    {
        $user = \PhocacartUser::getUser();

        if ((int) ($user->id ?? 0) > 0) {
            $rows = \PhocacartUser::getUserData();

            return (int) ($rows[0]->country ?? 0);
        }

        $raw = \PhocacartUserGuestuser::getAddress();

        if (empty($raw)) {
            return 0;
        }

        $data = \PhocacartUser::convertAddressTwo($raw, 0);

        return (int) ($data[0]->country ?? 0);
    }

    /**
     * Čita admin-konfigurisanu mapu country_code => currency_code iz
     * subform parametra.
     *
     * @since 0.1.0
     */
    private function getMappedCurrencyCode(string $countryCode): ?string
    {
        $mapping = $this->params->get('mapping', []);

        if (empty($mapping)) {
            return null;
        }

        foreach ($mapping as $row) {
            $rowCountry = strtoupper(trim((string) ($row->country_code ?? '')));

            if ($rowCountry === $countryCode) {
                return strtoupper(trim((string) ($row->currency_code ?? '')));
            }
        }

        return null;
    }

    /**
     * Nalazi Phoca Cart currency objekat (id, code...) po ISO kodu valute.
     *
     * @since 0.1.0
     */
    private function findCurrencyByCode(string $currencyCode): ?object
    {
        $all = \PhocacartCurrency::getAllCurrencies();

        if (empty($all)) {
            return null;
        }

        foreach ($all as $currency) {
            if (strtoupper((string) $currency->code) === $currencyCode) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * Gradi HTML banner za "suggest" mod - link koji vodi na postojeći
     * checkout.currency kontroler task (isti koji koristi standardni
     * currency modul), sa CSRF tokenom i return URL-om nazad na istu stranicu.
     *
     * @since 0.1.0
     */
    private function renderSuggestBanner(object $targetCurrency, ?object $currentCurrency, string $countryName): string
    {
        $csrfToken  = Session::getFormToken();
        $returnUrl  = base64_encode(Uri::getInstance()->toString());
        $formAction = Uri::root() . 'index.php?option=com_phocacart&task=checkout.currency';

        $currentCode = htmlspecialchars((string) ($currentCurrency->code ?? ''));
        $targetCode  = htmlspecialchars((string) $targetCurrency->code);
        $targetTitle = htmlspecialchars((string) ($targetCurrency->title ?? $targetCurrency->code));

        // POST forma umjesto GET linka - isti obrazac koji Phoca Cart core
        // koristi za sve state-changing akcije (checkout.currency task i sam
        // interno očekuje token kao POST/GET request parametar, ali forma je
        // pouzdanija za CSRF token svježinu nego pre-renderovan GET link).
        return '<div class="alert alert-info d-flex justify-content-between align-items-center py-2 px-3 my-2" id="currency-suggest-banner">'
            . '<span>' . sprintf(
                Text::_('PLG_SYSTEM_CURRENCYBYCOUNTRY_SUGGEST_TEXT'),
                htmlspecialchars($countryName),
                $targetTitle . ' (' . $targetCode . ')'
            ) . '</span>'
            . '<span>'
            . '<form method="post" action="' . $formAction . '" style="display:inline">'
            . '<input type="hidden" name="id" value="' . (int) $targetCurrency->id . '">'
            . '<input type="hidden" name="return" value="' . htmlspecialchars($returnUrl) . '">'
            . '<input type="hidden" name="' . $csrfToken . '" value="1">'
            . '<button type="submit" class="btn btn-primary btn-sm">'
            . Text::_('PLG_SYSTEM_CURRENCYBYCOUNTRY_SUGGEST_SWITCH') . '</button>'
            . '</form> '
            . '<a href="#" class="btn btn-outline-secondary btn-sm" '
            . 'onclick="document.getElementById(\'currency-suggest-banner\').style.display=\'none\';return false;">'
            . Text::_('PLG_SYSTEM_CURRENCYBYCOUNTRY_SUGGEST_DISMISS') . '</a>'
            . '</span>'
            . '</div>';
    }
}
