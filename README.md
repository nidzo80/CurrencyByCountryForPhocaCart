# Currency By Country for Phoca Cart

A small Joomla system plugin that suggests or automatically switches the shop's currency based on the **billing country** entered during checkout on a [Phoca Cart](https://www.phoca.cz/phocacart) store.

Built for a multi-currency store where currency is normally tied to site language (e.g. Swedish → SEK, other languages → EUR), but where visitors from other markets benefit from seeing prices in their own currency without having to switch language.

## Features

- **Three modes**, configurable per install:
  - **Off** — disabled, no effect.
  - **Suggest** — shows a dismissible banner under the checkout address ("Looks like you're shopping from Germany. Switch to EUR?") with a one-click switch button. Nothing changes automatically.
  - **Auto** — silently switches currency and reloads the page as soon as a mapped country is detected.
- **Admin-configurable Country → Currency mapping** (repeatable table, no code changes needed to add a market).
- Works for both **guest checkout** and **logged-in customers**.
- Uses Phoca Cart's own currency engine (`PhocacartCurrency::setCurrentCurrency()`) — the same mechanism the official currency module uses — so exchange rates, formatting, etc. all behave normally.
- No AJAX, no JS framework — plain PHP + a small inline script for the dismiss button.

## Requirements

- Joomla 5/6
- Phoca Cart 6 (PSR-4 / `SubscriberInterface` plugin architecture)
- At least one currency configured per target country in Phoca Cart (**Components → Phoca Cart → Currencies**)

## Installation

1. Download/build the plugin zip.
2. Joomla Admin → **System → Manage → Install** → upload the zip.
3. Enable it under **System → Manage → Plugins** → *"System - Currency By Country for Phoca Cart"*.

## Configuration

Open the plugin and set:

| Field | Description |
|---|---|
| **Mode** | `Off`, `Suggest`, or `Auto` |
| **Country → Currency mapping** | Add a row per country you want to affect, e.g. `DE → EUR`, `HU → HUF`. Countries not listed keep the store's default currency. |

The currency code must match a currency code that already exists (and is published) in Phoca Cart's own Currency settings — the plugin doesn't create currencies, it only switches to one that's already configured.

## How it works

Phoca Cart dispatches an `onPCVonCheckoutAfterAddress` event every time the checkout page re-renders after the billing/shipping address step is saved. This plugin listens for that event (registered as a standard Joomla **system** plugin, so it's loaded on every request and can catch the event regardless of which Phoca Cart "plugin group" originally triggered it):

1. Reads the billing country from the address just saved (guest session or logged-in user's address).
2. Looks up that country in the admin-configured mapping.
3. Compares the mapped currency to the currency currently active in session.
4. If they differ:
   - **Auto** → calls `PhocacartCurrency::setCurrentCurrency()` and redirects.
   - **Suggest** → renders a banner with a form that posts to Phoca Cart's own `checkout.currency` controller task (the same one the standard currency-switcher module uses), plus a "no thanks" dismiss link.

The event's result has to be reported via Joomla's `ResultAware::addResult()` API (not a plain `return` from the listener method) — this event class implements `ResultAware`/`ResultTypeStringAware`, and on Joomla 5+ the older `setArgument('result', ...)` approach throws.

## File structure

```
currencybycountry.xml              # Plugin manifest (group="system")
install.php                        # Enables the plugin on install
services/provider.php              # PSR-4 service provider
src/Extension/Currencybycountry.php
models/forms/mapping.xml           # External subform definition for the mapping table
language/en-GB/ , sv-SE/ , sr-YU/
```

## Known limitations

- Only affects the **checkout page** (fires on the address-save re-render). It doesn't change currency on category/product pages.
- "Suggest" mode's dismiss button only hides the banner for the current page view (CSS-only) — it doesn't persist across page loads. If that matters for your use case, it'd need a session flag added.
- Currency detection is based on the country the shopper *typed*, not GeoIP — by design, so it never overrides a deliberate manual choice on an unrelated field.

## License

GNU General Public License version 3 or later, matching Phoca Cart itself.


## Screenshots

<img width="1899" height="1131" alt="Snimak_ekrana 172" src="https://github.com/user-attachments/assets/81a26bd2-f534-42bf-adc8-2e4e43cfa741" />

<img width="1920" height="868" alt="Snimak_ekrana 173" src="https://github.com/user-attachments/assets/1c2121e0-3dbd-4cb3-93d6-057a4456b470" />

<img width="1887" height="790" alt="Snimak_ekrana 174" src="https://github.com/user-attachments/assets/f93fe587-1797-4511-9937-91e954bbfb34" />


