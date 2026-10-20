# Feature flags

_Split out of CLAUDE.md. Heading levels are as they were there._

### Feature flags

**`config/features.php` holds one switch per unfinished thing, read as `config('features.<name>')`, OFF by default,
set in `.env`.** A flag is documented here: what it does, and every place that reads it. Add to this list when a flag
gains a reader.

#### `alpha_testing` (`FEATURE_ALPHA_TESTING`)

**While it is ON, nobody can register without a valid, unused BETA KEY, and the admin has a *Beta keys* section.** OFF,
registration is open exactly as it was, the register form has no key field, and the admin section does not exist (its
routes 404 and the dashboard card is not drawn). Turning it off deletes nothing and forgets nothing: the table is simply
not asked. **It does not touch accounts that already exist, sign-in, password reset or the captcha.**

- **A key is `XXXX-XXXX-XXXX`**, from an alphabet with no 0/O or 1/I/L, single-use. `BetaKeys::normalise()` accepts
  what a person types (lower case, no dashes, stray spaces). A key that does not exist and one already spent get the
  same sentence (`auth.beta.invalid`), so a guess learns nothing.
- **Spent means `used_at` is set**, and `beta_keys.user_id` NULLS when the account is deleted and never cascades, so a
  deleted tester's key does not become valid again. Registration is one transaction: the key is checked before
  anything is written (`assertUsable`) and spent on the new user with its row locked (`claim`), so two people cannot
  use one key and a refused registration (bad email, say) does not burn it.
- **In the admin** the list shows each key, a status tag, and, for a spent one, the email and the character's handle
  (or "no character yet"). *Copy* copies the key and marks it **given out** (`given_out_at`, idempotent), which strikes
  it through in the list. *Generate a key* makes one new key. `given_out_at` is informational: it never stops a key
  working.

**Everything the flag touches:**

| Where | What it does with the flag |
| --- | --- |
| `config/features.php`, `.env.sample` | defines it and its env var |
| `App\Beta\BetaKeys` | the only writer of `beta_keys`; `BetaKeys::enabled()` is the one read of the flag in PHP |
| `app/Actions/Fortify/CreateNewUser.php` | when on: refuses a missing, wrong or spent key, and spends the key in the same transaction as the user |
| `resources/views/auth/register.blade.php` | when on: draws the *Beta key* field and the optional *What do we call you (for credits, on the thank you page)* field |
| `users.credit_name` (`2026_10_07_190000_...`) | that second field: nullable, trimmed (blank is NULL), 100 characters, validated before the key is spent, stored only while the flag is on, shown beside the email in the admin's key list; nothing in the game reads it yet |
| `database/migrations/2026_10_07_100000_beta_keys.php`, `App\Models\BetaKey` | the table and model (they exist whatever the flag says) |
| `App\Http\Controllers\Admin\BetaKeyController`, `routes/web.php` (`admin.beta_keys.*`) | every action is `abort_unless(enabled, 404)` |
| `resources/views/admin/beta-keys/index.blade.php`, `resources/views/admin/index.blade.php` | the section, and the dashboard card (drawn only when on) |
| `public/assets/js/ui-kit.js`, `ui-kit.css` | `data-copy-then` / `data-copy-mark` (POST after a copy that worked, then strike through) and `.strike` — both generic, nothing about keys |
| `config/strings.php` | `auth.beta.*`, `admin.beta_keys.*` |
| `tests/Feature/AlphaTestingTest.php` | both states of the flag |

**To remove the feature for good:** delete that table's rows of the list above, drop `beta_keys` in a migration, and take
the `BetaKeys` branch out of `CreateNewUser`.

