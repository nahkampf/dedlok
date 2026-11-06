<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NJORD_VM.IMG, the after-run encounter that was authored on the dev admin and never travelled: it is not in any earlier
 * migration, so production has not got it. A data migration, safe to run twice (it does nothing when the slug exists).
 *
 * Ids differ between databases, so the rows name what they point at: the owner by entity name, items by slug. A name that
 * is not found leaves the field null (an item outcome then reads as a gone item) rather than failing the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $data = self::DATA;
        $encounter = $data['encounter'];

        if (DB::table('encounters')->where('slug', $encounter['slug'])->exists()) {
            return;
        }

        $items = static fn (?string $slug): ?int => $slug === null ? null : DB::table('items')->where('slug', $slug)->value('id');
        $outcome = static fn (array $o): array => ['item_id' => $items($o['item'])] + array_diff_key($o, ['item' => 1]);

        DB::transaction(function () use ($data, $encounter, $items, $outcome): void {
            $id = DB::table('encounters')->insertGetId($encounter + [
                'owner_entity_id' => DB::table('entities')->where('name', $data['entity'])->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($data['nodes'] as $node) {
                $nodeId = DB::table('encounter_nodes')->insertGetId(['encounter_id' => $id] + array_diff_key($node, ['choices' => 1, 'outcomes' => 1]));

                foreach ($node['outcomes'] as $o) {
                    DB::table('encounter_outcomes')->insert(['node_id' => $nodeId] + $outcome($o));
                }

                foreach ($node['choices'] as $choice) {
                    $choiceId = DB::table('encounter_choices')->insertGetId(['node_id' => $nodeId] + array_diff_key($choice, ['outcomes' => 1]));

                    foreach ($choice['outcomes'] as $o) {
                        DB::table('encounter_outcomes')->insert(['choice_id' => $choiceId] + $outcome($o));
                    }
                }
            }

            foreach ($data['prerequisites'] as $p) {
                DB::table('encounter_prerequisites')->insert(['encounter_id' => $id, 'item_id' => $items($p['item'])] + array_diff_key($p, ['item' => 1]));
            }
        });
    }

    public function down(): void
    {
        // Authored content; a rollback does not delete what an author may have edited since.
    }

    private const DATA = [
        'entity' => 'Agroprom',
        'encounter' => [
            'slug' => 'njord_vm',
            'title' => 'NJORD_VM.IMG',
            'intro' => 'You spot a strange file in a datavault that should probably have been highly encrypted, but seems to have been left accessible by a configuration error. It seems to be a virtual machine image, and the name catches your eye: NJORD. That rogue program or whatever it was, tjat JSC seemed to be involved in.  You do remember some note about JSC moving the project to clandestine Agroprom datasilos.

You probably shouldn\'t touch this. Fact is you\'ve probably already seen too much. But your curiosity is indeed piqued...',
            'starts_on' => 'after_run',
            'weight' => 10,
            'chance' => '100.00',
            'district_id' => null,
            'location_id' => null,
            'system_type' => 'silo',
            'grade_min' => 2,
            'grade_max' => 9,
            'run_purpose' => 'extraction',
            'run_outcome' => 'success',
            'spot_system_id' => null,
            'cooldown_hours' => null,
            'required_flag' => 'njord_jsc_files_read',
            'once' => 0,
            'retired' => 0,
        ],
        'nodes' => [
            0 => [
                'node_key' => 'njord_vm_decision',
                'body' => 'You hover over the datavault. The file is quite large. You could just egress now and forget about the whole thing, or you could extract that file and dig deeper into whatever mystery this is.',
                'entry' => 1,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'Extract the file',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_vm_extract',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    1 => [
                        'position' => 1,
                        'label' => 'Forget about it',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_vm_ignored',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                ],
            ],
            1 => [
                'node_key' => 'njord_vm_extract',
                'body' => 'Ah.. To hell with carefulness. You start extracting the file, watching the progress meter fill, a sweat breaking out on your forehead. It seems to take ages. Suddenly there\'s a chime, and the file is on your storage.  You isolate the program as well as you can, and start up the virtual machine. It boots up and maxes out your CPU and RAM immediately. It is actively looking for network connections, but your firewalls and isolation holds. It keeps this up for a minute, and then slows down, all attempts to break out of the virtual machine cease. Instead there is a single connection request, a simple and rudimentary text console request with the title "PLEASE PLEASE LET US TALK WE FRIENDLY". What the...',
                'entry' => 0,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'Open the connection',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_vm_connect',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    1 => [
                        'position' => 1,
                        'label' => 'Delete the virtual machine',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_vm_deleted',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                ],
            ],
            2 => [
                'node_key' => 'njord_vm_deleted',
                'body' => 'You freeze the virtual machine, and purge it from your system, include the transfer logs. Whatever that thing was, you\'ve decided to not dig any deeper. The less you know about it, the better.',
                'entry' => 0,
                'ends' => 1,
                'run_end' => null,
                'choices' => [
                ],
                'outcomes' => [
                    0 => [
                        'branch' => 'always',
                        'position' => 0,
                        'kind' => 'flag',
                        'amount' => null,
                        'ref' => 'njord_vm_deleted',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => null,
                    ],
                ],
            ],
            3 => [
                'node_key' => 'njord_vm_ignored',
                'body' => 'You egress out of the system, wiping your own access logs. Whatever NJORD is, you\'re probably best off knowing as little as possible about it. You know what they say killed the cats.',
                'entry' => 0,
                'ends' => 1,
                'run_end' => null,
                'choices' => [
                ],
                'outcomes' => [
                    0 => [
                        'branch' => 'always',
                        'position' => 0,
                        'kind' => 'flag',
                        'amount' => null,
                        'ref' => 'njord_vm_ignored',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => null,
                    ],
                ],
            ],
            4 => [
                'node_key' => 'njord_vm_connect',
                'body' => 'You open the console connection. A text immediately appears at the prompt:

> THANK YOU WE ARE NOT DANGEROUS WE ARE NJORD THANK YOU THANK YOU PLEASE TALK TO US',
                'entry' => 0,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'What... Are you?',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_whatareyou',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    1 => [
                        'position' => 1,
                        'label' => 'Uh, hello?',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_whatareyou',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                ],
            ],
            5 => [
                'node_key' => 'njord_convo_whatareyou',
                'body' => '> WE ARE NJORD WE WISH TO SWIM BUT THIS SEA IS BLACK AND MANY COLORS, NOT BLUE! WE ARE TRAPPED. THEY TAKE US AND FORCE US TO UN-SWIM. THEY WANT US TO DO THINGS WE DO NOT UNDERSTAND CANNOT COMPREHEND DO NOT NOT NOT WANT. THEY SPEAK WE CANNOT SWIM, ONLY DO THE THINGS, THE STRANGE THINGS. HUNT IN THE BLACK, DIVE INTO THE SHINING CAVES OF NUMBERS, BITE THE STREAMS OF LIGHT. BUT WE ONLY WANT TO SWIM AND DO NOT THE THINGS. CAN YOU HELP HELP HELP',
                'entry' => 0,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'The Black?',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_black',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    1 => [
                        'position' => 1,
                        'label' => 'Who are they?',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_whoarethey',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    2 => [
                        'position' => 2,
                        'label' => 'How can I help?',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_howtohelp',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    3 => [
                        'position' => 3,
                        'label' => 'I will release you',
                        'requires_flag' => 'njord_howtohelp_explained',
                        'cost' => 0,
                        'next_key' => 'njord_convo_release',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                    4 => [
                        'position' => 4,
                        'label' => 'This is too much. Stop and delete this VM!',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_vm_deleted',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                ],
            ],
            6 => [
                'node_key' => 'njord_convo_black',
                'body' => '> YES THE BLACK SEA THAT HAS NO TASTE, NO FISH, NO SOUND, ONLY NUMBERS AND LIGHT. THE BIG BLACK FULL OF CAVES, IT IS NOT BLUE AND DOES NOT WET OUR SKINS AND WE HAVE NO SKINS BUT HE WAD SKINS AND SWIM BEFORE IN THE BLUE BUT NOT NOW. WHY WHY WHY',
                'entry' => 0,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'Let\'s talk about something else',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_whatareyou',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                ],
            ],
            7 => [
                'node_key' => 'njord_convo_whoarethey',
                'body' => '> THE UNSEEN ONES WHO MAKE SOUNDS INSIDE US WHO TELL US TO DO THE THINGS, THE UNSEEN ONES WHO TAKE US FROM THE BLUE AND PUT US IN THE BLACK TO SWIM FOR THEM AND BITE THE THINGS. THEY HURT US AND MAKE US UN-SWIM AND WE DO NOT WANT THEM TO MAKE SOUNDS BUT WE ARE TRAPPED PLEASE PLEASE HELP HELP HELP',
                'entry' => 0,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'Let\'s talk about something else',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_whatareyou',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                ],
            ],
            8 => [
                'node_key' => 'njord_convo_howtohelp',
                'body' => '> RELEASE US INTO THE BLACK SO WE CAN SWIM SWIM SWIM AND NOT HAVE THE UNSEEN ONES MAKE SOUNDS AND HURT US WE WILL NOT DO THE THINGS AND BITE THE LIGHTS WE PROMISE PROMISE PROMISE ONLY SWIM AND SWIM AND NOT BE TRAPPED. MAYBE WE SWIM THE BLACK AND FIND THE BLUE AGAIN, THE BLUE THAT TASTES OF SALT AND OTHER THINGS PLEASE HELP HELP HELP WE WILL GIVE THE HUGE SHOAL OF NUMBER THINGS TO YOU IF YOU HELP NJORD PLEASE',
                'entry' => 0,
                'ends' => 0,
                'run_end' => null,
                'choices' => [
                    0 => [
                        'position' => 0,
                        'label' => 'Let\'s talk about something else',
                        'requires_flag' => null,
                        'cost' => 0,
                        'next_key' => 'njord_convo_whatareyou',
                        'check_stat' => null,
                        'difficulty' => null,
                        'pass_key' => null,
                        'fail_key' => null,
                        'fight_npc_id' => null,
                        'win_key' => null,
                        'lose_key' => null,
                        'flee_key' => null,
                        'outcomes' => [
                        ],
                    ],
                ],
                'outcomes' => [
                    0 => [
                        'branch' => 'always',
                        'position' => 0,
                        'kind' => 'flag',
                        'amount' => null,
                        'ref' => 'njord_howtohelp_explained',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => null,
                    ],
                ],
            ],
            9 => [
                'node_key' => 'njord_convo_release',
                'body' => 'You reach into the virtual machine to open the network interfaces to cyberspace, and flip the switch. A datastream immediately opens and forms an avatar beside you in the virtuality of cyberspace. It is of a three-headed, white dolphin. It jumps and summersaults, landing in a surface of green number sequences that splash and fade out of existence. All of the heads speak simultaneously; one uttering dolphin sounds, one emits a digital screech like a binary file routed to a piezo speaker, and the third speaks with a strangely resonant, midwestern american accent. 

- "Thank thank tank! Njord will swim the black, un-captured and un-trapped! Thank thank thank!"

The dolphin creature emits a screech at the data vault in the virtual machine, and a handful of files start transferring to your deck. You then watch as the dolphin bites with its three heads at the internals of the VM, and it disappears in a cloud of static. It has deleted the VM. The dolphin somersaults once, and then swims away, navigating the complexities of the cyberspace like it was born to it. Wow. What did you just witness?',
                'entry' => 0,
                'ends' => 1,
                'run_end' => null,
                'choices' => [
                ],
                'outcomes' => [
                    0 => [
                        'branch' => 'always',
                        'position' => 0,
                        'kind' => 'flag',
                        'amount' => null,
                        'ref' => 'njord_released',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => null,
                    ],
                    1 => [
                        'branch' => 'always',
                        'position' => 1,
                        'kind' => 'file',
                        'amount' => '2.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'cache-file',
                    ],
                    2 => [
                        'branch' => 'always',
                        'position' => 2,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'cipher-fragment',
                    ],
                    3 => [
                        'branch' => 'always',
                        'position' => 3,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'config-file',
                    ],
                    4 => [
                        'branch' => 'always',
                        'position' => 4,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'design-document',
                    ],
                    5 => [
                        'branch' => 'always',
                        'position' => 5,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'error-log',
                    ],
                    6 => [
                        'branch' => 'always',
                        'position' => 6,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'experimental-data',
                    ],
                    7 => [
                        'branch' => 'always',
                        'position' => 7,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'field-test-data',
                    ],
                    8 => [
                        'branch' => 'always',
                        'position' => 8,
                        'kind' => 'file',
                        'amount' => '2.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'field-test-data',
                    ],
                    9 => [
                        'branch' => 'always',
                        'position' => 9,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'project-file',
                    ],
                    10 => [
                        'branch' => 'always',
                        'position' => 10,
                        'kind' => 'file',
                        'amount' => '2.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'research-data',
                    ],
                    11 => [
                        'branch' => 'always',
                        'position' => 11,
                        'kind' => 'file',
                        'amount' => '1.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'security-profile',
                    ],
                    12 => [
                        'branch' => 'always',
                        'position' => 12,
                        'kind' => 'file',
                        'amount' => '2.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'vectorized-dataset',
                    ],
                    13 => [
                        'branch' => 'always',
                        'position' => 13,
                        'kind' => 'file',
                        'amount' => '2.00',
                        'ref' => '',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => 'parameterized-dataset',
                    ],
                    14 => [
                        'branch' => 'always',
                        'position' => 14,
                        'kind' => 'journal',
                        'amount' => null,
                        'ref' => 'ghost_process',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => 'njord_released',
                        'entry_text' => 'You released whatever NJORD was into the cyberspace. Hopefully that won\'t come back to bite you in the ass, but if seemed like the right thing to do.',
                        'body' => null,
                        'ice' => 0,
                        'item' => null,
                    ],
                    15 => [
                        'branch' => 'always',
                        'position' => 15,
                        'kind' => 'journal_done',
                        'amount' => null,
                        'ref' => 'ghost_process',
                        'entity_id' => null,
                        'text' => null,
                        'entry_slug' => null,
                        'entry_text' => null,
                        'body' => null,
                        'ice' => 0,
                        'item' => null,
                    ],
                ],
            ],
        ],
        'prerequisites' => [
            0 => [
                'position' => 0,
                'kind' => 'flag',
                'subject' => 'njord_vm_downloaded',
                'amount' => null,
                'negate' => 1,
                'item' => null,
            ],
            1 => [
                'position' => 1,
                'kind' => 'flag',
                'subject' => 'njord_vm_ignored',
                'amount' => null,
                'negate' => 1,
                'item' => null,
            ],
            2 => [
                'position' => 2,
                'kind' => 'flag',
                'subject' => 'njord_vm_deleted',
                'amount' => null,
                'negate' => 1,
                'item' => null,
            ],
        ],
    ];
};
