# CHEATSHEET.md

## Characters

### Checks (every roll in the game)
- One die, `CheckRoller`. Chance = `1 / (1 + e^-((value − difficulty) / 8))`. The 8 is `checks.spread`.
- Margin is value minus difficulty. Margin 0 gives 50%. +8 gives 73%. +16 gives 88%. −8 gives 27%. −16 gives 12%.
- A check is never certain and never hopeless: chance is clamped to 0.85% – 99.15% (`certainty_floor`).
- Some checks have their own floor: exploration 0 (it can be hopeless or certain), finding a safehouse 2%, decryption 2%.
- A chance modifier (like Ensnare) multiplies the chance, then the floor is applied again.
- A roll passes when the die lands strictly below the chance.

### Modifiers
- Everything that changes a number is data, turned into a `Modifier` and run through one `Resolver`. 
- Sources: skills, items, programs, implants, drugs, injuries, perks, worn armour, effects (mood, fatigue).
- Any modifier invented without a spec stays small: 2–5%.

### Attributes
- Ten attributes, each 0–100 (100 is the lifetime cap).

### Derived stats (formulas in `config/stats.php`; each term is attribute ÷ divisor, rounded up)
- Max HP = Meat + Muscle/2 + Focus/2. Focus counts as willpower here.
- Max SI = Focus/2 + Memory/2 + Intellect/2 + Meat/2.
- Max fatigue = 6 × Focus + 6 × Meat + Social. All attributes at 20 gives 260.
- Stealth = (Intellect + Focus + Memory + Perception) ÷ 8.5. An IDS rolls against it. Floor 0.
- Egress = (Reflex + Focus + Intellect) ÷ 4. Rolled when leaving a system. Floor 0.
- Exploration = (Focus + Perception + Senses) ÷ 4.
- Stealth, egress and exploration are "pools": sum first, divide once.
- HP, SI and ICE are kept to two decimals (`Points::of()`). Never cast one to int.

### Resources
- Current HP and current SI are stored, between 0 and their max. At 0 the character goes to the waiting room.
- If a max drops, the current value is clamped down. If a max rises, nothing is healed.
- Known issue: fatigue lowers attributes, which lowers max SI, which clamps current SI down for good. No fix yet.

### Fatigue
- Costs per act: grid step 0.1, scan 0.5, run turn 1, fight round 2.5, travel pass 5, explore pass 7, ask around 0.5, stakeout 1, scope out 0.5, break in 10, rob 10, burn 15. The pod gives −1.
- Tired at 75% of max, Drained at 90%, then Exhausted. Each lowers Focus, Intellect, Reflex and more (`config/effects.php`).
- Collapse: down for 120 s, wakes at 95%, takes 20% damage. 20% chance of a mugging for 10–33 creds. `TBD`: exact trigger.
- Fullness (food) caps at 50 and drains 0.07 per minute.

### Mood
- Runs −100 to 100 and drifts toward 0 by 0.01 per minute (0.03 beyond ±70).
- Bands: despondent at −75, low at −40, good at 40, elated at 75. They change Focus (−4, −2, +1, +2) and Social.
- Events: fight won +8, fight lost −10, collapse −15, injury −1, meal +2, dose +2, raided −10, burned −30, break-in +5 (failed −5), rob +10, burn +2.

### Skills
- Levels 1–5, ranks 1/2/4/8/16. Skills train in a queue.
- Training speed per minute = primary attribute × 0.121 + secondary × 0.0762.
- Level 1 needs 242 points; each level needs about 5.95× the last.
- A skill can grant modifiers and exposes `check.*` and `skill.*` stats.

### Injuries
- An injury is permanent until a clinic mends it: it lowers max HP (`physical_trauma`) or max SI (`neural_damage`).
- Each new hit deepens the same injury.
- Sources: ICE Flatline, overdose, botched surgery, the `maim` combat tag, some encounters.

### Drugs
- Tolerance cuts a dose's effect: −10% at 25, −20% at 50, −30% at 75.
- Tolerance decays 3 per day, addiction 1 per day. Withdrawal starts at addiction 40.
- Overdose: you can take 2 doses plus 1 per 25 tolerance before going over. Each excess dose costs 2 damage plus family HP: stimulant 4, opiate 3, sedative 3, psychedelic 2, nootropic 1, medical 4.
- Lethal at this many excess doses: stimulant 3, opiate 2, sedative 2, medical 3. Psychedelic and nootropic are never lethal.

### Damage
- Eight types: blunt, slashing, penetrating, fragmentation, electrical, thermal, chemical, neural.
- Resistance, vulnerability and immunity are modifiers against a tag set.
- Penetration scales only the negative percentage modifiers (e.g. armour) of a hit.
- Armour is per body part. Armour only counts for a part it covers.
- Other causes: ICE, burnout, overdose, exhaustion, encounter, tunnel, readiness loss, run cancelled.

### Combat
- Distance starts at 2, maxes at 6. Fleeing works beyond 2.
- Hit quality by roll: near miss 10%, glancing 25%, partial 50%, clean hit 75%, dead on 100% of damage. A critical is ×5.
- Each round of a burst loses 7% accuracy. Unarmed does 2 blunt.
- Where a hit lands: torso 37%, each leg 18%, each arm 9%, head 9%.
- Pistol, SMG, rifle and shotgun need level 1 in their skill to equip. There is no dodge.
- Win pay by NPC grade 1–5: 20–60, 50–150, 120–350, 300–800, 700–2000.
- Loss: mugging 30–50, maim 10–20. `TBD`: what unit those are.
- All combat numbers are unmeasured. Balance is deferred until real NPCs exist.

### Safehouse
- Rent lasts 24 h, then 24 h of grace. Resting restores 0.083 SI and 0.05 HP per minute.
- Workout and study: 2 fatigue, 0.3 progress. Simstim: 1 fatigue, +0.4 mood, up to 7200 s per day.
- Patch: +10 HP, cooldown 4 h. Meditate: +10 SI for 10 fatigue, cooldown 1 h. Shower: −15 fatigue, +1 addiction, cooldown 8 h.
- Finding a safehouse takes 5 passes. Stakeout: 50% nothing, 40% small find. Ask around: 60% nothing, 15% clues, 15% misdirected, 10% nutjob. Police show up 15% of the time.

## Cyberspace

### The grid
- 128 × 128, 70% occupied. Systems have grades 1–9; counts per grade: 260, 220, 180, 130, 90, 60, 40, 15, 5.
- Scan difficulty by grade 1–9: 10, 18, 26, 34, 44, 54, 66, 78, 92. A scan result lasts 48 h. Skill: Systemprofiling.
- OS value (0–5): 0, 11.9, 23.4, 33.7, 40.7, 65. OS check difficulty 24.

### Defences by system grade 1–9
- Presence: 10, 18, 26, 36, 46, 58, 70, 82, 95.
- IDS detection: 10, 17, 24, 30, 36, 42, 48, 53, 58. Rolled against your Stealth.
- Tracer detection: 2, 4, 7, 10, 14, 19, 25, 32, 40. Tracer rate: 4, 5, 6, 8, 10, 12, 15, 18, 22.
- Always present by type: silo has IDS and ICE, relay has IDS and tracer, compute has ICE and tracer.

### Egress (leaving a system)
- Difficulty is 3 at grade 1, plus 2 per grade (19 at grade 9). Each awake ICE adds a term of 1.
- Ensnare lowers the chance by 25%, 40% or 55% (level 1–3).

### ICE attack tags
- Eject: chance 10/20/30%, damage 3/6/10.
- Virus: chance 15/25/35%, damage 1/2/3 per turn for 6/8/10 turns.

### ICE defeat tags (what happens when ICE wins)
- Flatline: max SI loss 4–10, 9–16 or 14–19 by level.
- Skim: takes 10%, 30% or 60% of your creds.
- Erase: one item, 50% of items, or all.
- Neural shock: SI damage 8–15, 15–28 or 28–45.
- Surge: 45%, 45% or 55% chance to burn 1, 2 or 3 modules.
- Bounty: 10 at ICE level 1, up to 65 at level 12, in steps of 5.
- Freeze: SI damage 25–75, and a 12% chance of a flatline.

### Trace and heat
- Trace advances per step by grade 1–9: 95, 90, 80, 70, 60, 50, 40, 30, 20.
- A trace adds 2 heat per grade. A burn adds 2.5 heat per grade.
- Bounty: below heat 5 there is none. Certain at heat 100. Pays 50 per heat plus 25% per grade. Cooldown 7 days.
- Bloodhound hunt: 50% chance at heat 5, certain at 100. Cap 35 heat, cooldown 60 min.
- Hunt grade by heat: 1 at 5–24 (ICE 1–2, 250–350 ¤), 2 at 24–43 (ICE 3–4, 400–550), 3 at 43–62 (ICE 5–7, 550–700), 4 at 62–81 (ICE 8–9, 700–850), 5 at 81+ (ICE 10–12, 850–1000).
- Winning cuts heat by 5 per grade. Losing costs 5–15% of heat. 8% chance of a program drop.
- Heat cools every night at 05:10.

### Loot
- Creds: grade 1 holds 40–120, grade 9 holds 5000–12000.
- Data: chance per 1000 goes 250 → 900 across grades. Size 2–6 QB (grade 1) to 90–220 (grade 9). Value 25–50 to 1000–2000. A broken file is worth about double.
- Items: chance per 1000 goes 100 → 620. At most 4 items.
- Bias by type (data / item / creds, per 1000): silo 1500/900/700, compute 700/900/1400, relay 900/1300/900.
- Encryption by grade: cinder 1–4, halcyon3 3–6, blackglass 6–8, ouroboros 8–9.

### Decryption
- Difficulty: cinder 20, halcyon3 35, blackglass 50, ouroboros 65. Chance floor is 2%, so a hopeless 5-minute cycle averages about 4 hours.
- Queue holds 10.

### Exchanges
- Standing needed for a grade 1–5 desk: 0, 5, 20, 40, 60.
- Most exchanges have 3 grades. Khaleej has 5. Ninth Circle, Anansi and Joint Signals have 4. Directorate and Golden Path have 2.

### Wardens, tunnels, fortresses
- Warden detection by level 1–5: 35, 45, 55, 65, 75. A warden steps every 60 s.
- Tunnel: costs 2 SI, cooldown 5 min.
- Infiltrate: 3 turns, difficulty 70, a 10-minute window.
- Lockdown lasts 2–6 h. A system can be raided 3 times.
- Fortress defaults: ICE attack 5 and integrity 30, IDS detection 20, cloak strength 30. Defences decay 2% per day. A wallet raid takes 25%. Kernel integrity 100.
- Backdoor siphon by grade: 45 up to 290. A backdoor ages at 1.0 + 0.05 per day, capped at 10.
- Fortress turn is 30 s. Frozen for 8 h. Abandoned after 48 h.
- Random encounters: grid step 2%, system 5%, run 5%.

## City

### Travel, explore, encounters
- Travel takes 30 s. The pod takes half the time, costs 25 ¤ and gives −1 fatigue.
- An explore pass takes 240 s. Difficulty: trivial 14, difficult 25, obscure 38, secretive 51. No floor.
- Encounter chance: 5% per travel, 8% per explore.
- Encounter rarity (weight out of 100): ultra rare up to 2, rare up to 8, uncommon up to 25, common up to 100.
- Odds words shown to the player: long shot ≤10%, unlikely ≤30%, risky ≤45%, even ≤60%, likely ≤80%, safe ≤100%.

### Surgery
- Install takes 300 s, remove takes 120 s. Difficulty 20 at tier 1, 35 at tier 2. A botched job causes 2 injury per tier.

### Shops and market
- Selling back to a shop resets every 8 h. The market reseeds every 8 h.
- Seed chance by rarity: common 100%, uncommon 70%, rare 20%, ultra rare 5%.
- Demand: 7-day window, threshold 0.5, price rises up to ×4.
- Clinics charge per point: neural about 95, physical about 83.

### Standing
- Runs −100 to 100. Bands start at: hostile −100, hated −60, cold −20, neutral 0, known 5, trusted 20, allied 60.
- Gains: achievement +0.5, mission +0.01. Losses: noticed −0.02, backdoor found −0.05, traced −0.05, defeated −0.05, raided −0.10, burned −1.00.
- Drifts up 0.0025 each night. Spending in a loyalty store gives 0.001 per loyalty point.
- Lockjaw standing of 10 unlocks the darknet.

### Insurance
- Two policies: property and medical, at 50%, 70% or 90%. A term is 14 days, with a reminder 2 days before it ends.
- Price per term: property 400 / 900 / 1800, medical 250 / 600 / 1200.
- Property pays the share of catalogue base cost for items lost to ICE Surge or Erase.
- Medical pays the share of points × clinic average for a Flatline, a maim or a deepening encounter injury.
- Self-inflicted losses (surgery, overdose, selling) are never covered.

## Misc

### Crafting
- Needs `code_crafting` level 1 plus the kind's own skill at level 1. At most 10 components.

### Programs and decks
- One thread per program by default. A running program costs 2 qFLOPS and 2 memory. The tracker ticks every 60 s.
- Deck onboard storage is 16. A character has 3 belt slots.

### Wallet
- Only `Wallet::move()` changes creds, and every move carries a `Reason`.

### Schedules
- Market seed every 8 h. Quest runner every 30 min. Drug metabolism at 05:20. Heat cooling at 05:10. Insurance reminders every hour.
