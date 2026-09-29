<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\PatchNotes;
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

        // A new player has read everything so far: the patch notes are for
        // what changes after they arrive, never a history lesson on the way in.
        $user = User::create([
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);

        $user->forceFill(['last_seen_changelog' => PatchNotes::latest()])->save();

        return $user;
    }
}
