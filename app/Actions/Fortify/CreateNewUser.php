<?php

namespace App\Actions\Fortify;

use App\Beta\BetaKeys;
use App\Models\User;
use App\Support\PatchNotes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Registration creates the ACCOUNT only. The handle, archetype and
     * attributes are chosen on the character-creation screen, and the
     * RequireCharacter middleware sends the player there on their next
     * request until they have one.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        // ALPHA TESTING (config/features.php): while it is on, no beta key means no account. Checked before anything is
        // written, and spent below inside the same transaction that creates the user.
        $keys = new BetaKeys;

        if (BetaKeys::enabled()) {
            $keys->assertUsable($input['code'] ?? null);
        }

        return DB::transaction(function () use ($input, $keys): User {
            // A new player has read everything so far: the patch notes are for
            // what changes after they arrive, never a history lesson on the way in.
            $user = User::create([
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $user->forceFill(['last_seen_changelog' => PatchNotes::latest()])->save();

            if (BetaKeys::enabled()) {
                $keys->claim($input['code'] ?? null, $user);
            }

            return $user;
        });
    }
}
