<?php

namespace App\Actions\Fortify;

use App\Models\Character;
use App\Models\User;
use App\Rules\ValidHandle;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * The handle belongs to the character, so this writes to two tables.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'handle' => [
                'required',
                'string',
                new ValidHandle,
                Rule::unique(Character::class, 'handle')->ignore($user->character?->getKey()),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ], [
            'handle.unique' => __('auth.handle_taken'),
        ])->validateWithBag('updateProfileInformation');

        DB::transaction(function () use ($user, $input): void {
            $user->character?->update(['handle' => ValidHandle::normalize($input['handle'])]);

            if ($input['email'] !== $user->email && $user instanceof MustVerifyEmail) {
                $this->updateVerifiedUser($user, $input);

                return;
            }

            $user->forceFill(['email' => $input['email']])->save();
        });
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
