## 0.89.0 (2026-11-06)

- Lots of work on co-op; better ux, some gates put in place to stop things that can break a coop run etc. It seems to work much better and smoothly now.
- Things that affect HP and SI damage from ICE were labelled "ICE damage" which was easy to mistake as ICE SI damage. This has been clarified now.
- Flood had the help text "No co-op" which is exactly wrong, you almost always need coop to pull it off.

## 0.88.0 (2026-11-05)

- Achievements you haven't earned are greyed out
- There is a new type of program: Scanner swarms, and a single program in that category: Wotan. Scanner Swarms are run as background processes, and jump around scanning systems at random. They do this much less efficiently than an active scan, but let's you get scans done without the manual labor. Swarm scans don't count towards systems scanned for the purpose of achievements. 
  - No copy of Wotan is available, only the designs (BNI and Lockjaw).
- New cyberspace mission: Procure. Deliver X specific components to an exchange.
- Encounters can now write very specific files as an outcome. Scripts, programs, components or a new type: text files. These can be read from the stash.
- Lots of designs modified, to include some of the unused components (like Research Data or Customer Records).

## 0.87.0 (2026-11-04)

- There was a missing enum update to the mission statuses, so declining a mission resulted in a http 500. Fixed now.

## 0.86.0 (2026-11-03)

- Being cornered by a bloodhound stops all non-essential background processes to free up resources for your best icebreaker - but the state before turning processes off is now stored, and if possible processes are started back up again automatically once the bloodhound confrontation is over.
- Some bonuses should not go in the regular resolver, these are "flat" ops. A deck hardware that provides a flat bonus should not be affected by (for instance) fatigue debuffs. Passive hardware, implants, kernels are exaples that should apply their bonuses without being recalculated.
- A few more fixes for co-op runs, to make it less confusing and more apparent if a participant dropped out of the run or not. We're not 1.0 on coop yet, but getting better at least :)

## 0.85.0 (2026-11-01)

- TL;DR lots of bugs for co-op runs dealt with.

## 0.84.0 (2026-10-31)

- There is now a user tasklist in _Tasks & Missions_. You can set priority, check it when it's done, and see completed tasks (and reactivate them).
- A pretty big rebalance of how difficuly, Operating Systems and core checks work. The TL;DR is that core checks became more difficult now, and you really want to have your OS skill (for instance AuraOS) at level 5 when making a core breach.
  - A few things were introduced to make it easier: OS Internals skills (AuraOS Internals etc). These reduce the difficulty for their inteded OS _only_. They are available as imprints on a few Exchanges.
  - Two new utility programs (single-threaded, background process): SecRing Ablater (works against AuraOS and Prism-V) and CoreMod Isolator (works against IRONCLAD and Lattice) also reduce difficulty. Available as designs from a few select Exchanges.
- Scanners are now pre-checked in the Suspend box on the Run planning screen.
- There was a small bug where suspending decryptors didn't affect the cpu/ram calculation on the run planing screen, now fixed.

## 0.83.2 (2026-10-30)

- Plugged an exploit where you could milk the exchanges for the missions you wanted

## 0.83.1 (2026-10-29)

- An update to 0.83.0: We also needed to support CUU/CUB/CUB, absolute position and save/restore cursor to cover all ANSI cases.
- You can now preview your bio rendered beautifully ("Preview" button).

## 0.83.0 (2026-10-29)

- What is a hacker game without ANSI art? Bio now supports a subset of ANSI escape codes (reset, foreground, background, high intensity and cursor forward). ANSI is rendered as an 80-char width 8px cell format. SAUCE is supported (for reading the ICE flag), but is stripped from output.
- Option to upload ANSI file to bio.

## 0.82.0 (2026-10-29)

- BNI, Golden Path, JSC, Lockjaw and Directorate of Revenue Enforcement now buys various components at their exchanges.
- Eidetic imprint cards still had a few cases where they didn't display that they were already injected.

## 0.81.0 (2026-10-29)

- There is now a box in the run planning screen to suspend background processes before the run, to free up resources. These are then restarted automatically after the run.
- In "Bookmarks" there is now a button that opens a modal that contains all the systems you have backdoors on.
- Encounter chances tweaked. 40% base chance on district exploration, and 11% on travel.
- Some placeholder strings edited
- Trace planel during a run didn't display the hops, fixed now

## 0.80.0 (2026-10-29)

- **Work Out** and **Study** relabalanced: 
  - Study now costs 2.5 fatigue / min (up from previous 2).
  - Work Out now costs 3 fatigue per minute (up from previous 2). Working Out is more tiring than studying.
  - Curve for the progress rate (P) per minute: P = (base progress rate * reference) / attribute pool, where BPR = 0.3, reference = 60 (a reference point, a pool of say 3 attributes at 20 each), and attribute pool is the value of Meat+Motor+Muscle or Intellect+Memory+Focus. This means that P = BPR (0.3) / minute for a pool value of 60, and gives a faster progress rate if you're below that, and a slower progress rate as your attributes increase (you need to hit 100 progress to gain a point).
  - Both activities cause a -0.075 to Mood per minute. When reaching 100% progress and getting an attribute point, Mood gets +10.
- Resting now also affects Mood. +0.05 Mood / fatigue point gained while Mood < 0, +0.005 while Mood >= 0. This means Mood regain scales with fatigue regain rates for various safehouses.
- Meals were slightly too good at reducing fatigue, and have been dropped by -2 across the board at various safehouses.
- Study/Work Out messages changed from Standard to Priority (ie "point gained").

## 0.79.0 (2026-10-28)

- Programs can now have a "family", and be targeted by modifiers.
- New utility type programs: Compressors. These give you more storage space while they are active.
- KOMPRESSOR.EXE is available on the market, and the more advanced OmniStacker 5 isn't.

## 0.78.1 (2026-10-28)

- Several small bugs when checking for stack id for items, which caused things like items moving from deck to safe house etc. Should all be resolved now.
- Similarly programs that were not on-deck could also be run in certain situations
- Assessors sometimes remembered the last loadlout and got the CPU calculation all messed up. Fixed now.

## 0.78.0 (2026-10-27)

- Messages are now either Priority or Standard. Most low-urgency notifications are standard, while all player written and/or urgent/important messages are Priority.
- There are now essentially two inboxes, one for priority and one for standard, and the badge changes color depending on what's unread.
- Message chime only sounds on priority messages
- Cyberdecks now show their stats/slots on their item cards.

## 0.77.0 (2026-10-27)

- New utility programs: Assessors. These give you a roough estimate of your success chance for an intrusion run. They have different precisions - the more precise ones are rare and not available on the market.

## 0.76.1 (2026-10-26)

- Implants, weapons and ammunition actually list their stats/effects on the market now

## 0.76.0 (2026-10-26)

- New: **Insurance**. Locations can now offer insurance policies of two kinds: Property or Medical. Property covers item loss, and medical covers permanent HP/SI injury, and they both come in 50%, 70% or 90% coverage (percentage of the list price of property lost, or of the average cost to restore injured SI/HP at clinics). Insurance policies run for 14 days and must then be renewed.
- Two places sell insurance: TraumaTeam in Downtown sells Medical, and Vectra Indemnity Corp in Eastside sells Property.
- The Run screen incorrectly used a cast to integer for determining if an ICE had 0 integrity left, which could lead to situations where it looked as if the ICE had been neutralized but it could still attack.

## 0.75.3 (2026-10-24)
- Jah bless, big up! LockJaw a release di Darknet subnet access fi di real head dem wi a put in di hard wok. You affi reach a proper rank fi drop inna di circle.

## 0.75.2 (2026-10-24)

- Bugfix: Defeats against an ICE with multiple defeat tags could be resolved multiple times. This has now been covered by a transaction.

## 0.75.1 (2026-10-23)

- Expansion cards, icebreakers and analysers now explicitly state their modifiers/effects

## 0.75.0 (2026-10-23)
- The dot that shows other players on the cyberspace grid can now be clicked to display who's there
- The deck hardware wasn't seeded to Chrome Palace. It is now, however.
- Extraction, Transport and Denial missions doubled their loyalty payouts
- Extraction jobs didn't show a green tick when they were ready to be handed in

## 0.74.0 (2026-10-23)

- Fix: Fortresses in subnets now only target subnet systems with Tap and Wiretap.
- Fix: A bug caused ICE and IDS to lose strenth in the first second after installing them
- Removed: Electrode and Neuralink ports are now gone. It was a mechanic that didn't pan out.
- Content: LOTS of new deck hardware. All available at Chrome Palace for now, some might be pulled off market later and put into encounters, loot or loyalty stores.

## 0.73.1 (2026-10-22)

- Study, Work out and Simstim now hook into the one-minute clock cycle, so that the fatigue meter actually updates correctly.
- The fatigue box on the Character screen now lists the current effects
- Slight UI change for attributes

## 0.73.0 (2026-10-22)
- New achievement: **Netrunner**. Make a successful run on a grade 9 system.
- New program: **Wormhole**. Creates a "bridge" between a data fortress in a subnet to the main grid, bypassing the gateway. Comes with a cost and a cooldown.
- Encounters can now send messages.
- Stash is now sorted by type, alphabetically.
- Stash remembers which tab you were on
- Starting a decryptor now continues the decryption queue
- Dropped achievement standing gain from +1 to +0.5
- Safehouse UI fixes
- Error pages now "in character"
- Part of the Njord encounter chain fixed

## 0.72.1 (2026-10-21)
- "Work out" and "Study" could push you beyond fatigue collapse, it now halts 1 point before that. When halting that way, it puts you in rest mode automatically.
- "Work out" and "Study" now also have a small progress bar to see how far along you are towards _GAINS_!
- Exchange desks now show their real grade
- The Hitachi expansion board had the correct effect, but Expansion Gate modifiers were never applied to the deck

## 0.72.0 (2026-10-21)

- New: **Contracts**. You can now put an item up as a contract. It is basically the same as putting it on the market, but with a specific recipient who is the only one that sees it and can buy it.
- Overview map now allows you to copy addresses or coordinates.
- Overview map now distinguishes between empty nodes and unexplored

## 0.71.2 (2026-10-21)

- The "Known systems" modal now has a button for "Overview map" that shows you the whole of the grid, as you know it.
- The ICE health bar in Bloodhound encounters (plus a few other health bars elsewhere) were not updating, fixed now
- Changed the wording for BLoodhound logs
- "Running" modal "Cancel" changed to "Close"

## 0.71.1 (2026-10-21)
- Co-op runs now have some fixes to make it more streamlined.
  - Run isn't aborted because a runner egresses of goes down, it carries on until it's completed or everyone is out
  - Some edge cases covered that could result in an ambigious run, stale data or hung progress
  - Changed so that everyone sees the same log
  - No forced waiting on the turn timer, if everyone has made their action the turn progresses

## 0.71.0 (2026-10-21)

- UI: Removed effects from the attribute table in Character sheet. Attribute names are now hoverable to discover current effects
- Golden Path now hands out _Path of Repentance_ scripts if you do work for them. They are said to help overcome addictions, but... Golden Path is also a cult.

## 0.70.0 (2026-10-21)

- New utility program: **Tracker**. Give it a filter (owner, type, grade) and release it - and it will search the grid in the background until it finds the requested node. 
- "Show on market" is now "Put on market"

## 0.69.2 (2026-10-21)
- "Running" window misbehaved; running or stopping program hung the window in an incomplete state. Fixed now.
- A decryptor in the "Running" window that is decrypting a file will now say so.

## 0.69.1 (2026-10-20)
- Districts how have an image, and the safehouse too. Temporary eye candy during alpha phase, this has got to be cleared or removed later.
- Some UI tidying on the district page

## 0.69.0 (2026-10-20)

- New safehouse actions: **Work out** (slowly raises Meat, Motor and Muscle), **Study** (slowly raises Intellect, Memory and Focus), **Simstim** (raises Mood). All at the cost of fatigue. Not all safehouses have these actions.
- Resting at safehouses now heals a bit of HP as well
- Clinics can now do partial treatments, if you can't afford the full treatment
- Being incapacitated and losing items/programs/files now sends a message containing what was lost
- Fix: Destroying something in a stack destroyed the whole stack. Now takes quantity
- Fix: The "Running" window closed after each stop/run, now stays open
- Replying to a message now reuses the title and quotes the previous message

## 0.68.1 (2026-10-20)

- UI: Fixed skill list that got broken

## 0.68.0 (2026-10-20)
Lots of new features:

- **Subnets**: Smaller networks locked behind gateways on the main grid.
  - Have their own address space (a prefix followed by a 2-byte hexadecimal number), like `foo:3FC9`. Subnet max size is therefor 256x256, but most subnets will be substantially smaller than that
  - Subnets often have **Wardens** roaming. They are automated sentry programs interrogating and removing intruders. They are visible on the grid and will wander around. Getting too close to one will trigger their attention and may cause them to attack.
  - Entering a subnet requires either a valid gateway key, or making an **Iinfiltration** run (new run type) on the gateway. Infiltration runs are very difficult.
  - Exiting a subnet through the gateway is always free
  - Some ICE in Subnets now have the tag **Eject**, which may throw you out of the subnet.
  - Data fortresses can be installed in subnets. You will need a Subnet Certificate (can be extracted from subnet nodes).
  - Data Fortresses can only be supplied by Leechers installed on the same subnet 
  - New fortress daemon: **Secure Tunnelling**. Creates an encrypted connection to the main net, allowing you and authorized data fortress users to jump directly to the fortress, bypassing the gateway.
- New ICE tag: **Virus**. On a successful infection, you will take SI damage every turn for a few turns. Antiviral programs now also available, that cuts down on infection time.
- New mission: Denial. Flood a node with junk data and bogus connection requests until it becomes overwhelmed and locks down. This is rarely a solo activity.
- Resting at a safe house now also slowly heals Synpatic Integrity
- Some UI fixes for _Known Systems_. Fully scanned systems marked with a checkmark, and you can filter on fully scanned
- TAO ("Tailored Access Operations") is a a new, military entity that you can interact with. 

Please note that much of this content relies on encounters, flags etc that are not authored yet, so may be unavialble or unreachable right now.

## 0.67.0 (2026-10-19)

- You can now paste (non-animated) images into chat. THey live for a week before being removed.
- ICE and Icebreakers did their damage cast to integers, so any fractions caused by skill application was lost. They are now decimals instead.
- UI: "Forget" and "Give it up" buttons now require confirmation
- UI: "Hand it in" is now greyed out until you can actually hand the job in
- UI: Delivery jobs no longer reveal coordinates until you have taken the job, to further reduce the risk of "cheating"
- UI: Placement jobs now correctly state "Place backdoor" instead of "Install siphon" inside the run
- UI: Exchanges now have accordions for mission board, loyalty store etc.
- Two new achievements: Loyal Service (100 missions) and The Grind (2000).

## 0.66.3 (2026-10-19)

- Fixes a load of skills that were not displaying their effects properly

## 0.66.2 (2026-10-19)

- There was an exploitable gap where you could perform delivery missions withot risk. That's fixed now: you must be present at the exchange node in order to accept a mission
- The Mood box now behaves like the fatigue one

## 0.66.1 (2026-10-19)

- The safehouse you live in now shows up on its district's map and in the list of places you know.
- "Your safehouse" is now a green button.

## 0.66.0 (2026-10-19)
- Updated safehouse data
- Updated food places

## 0.65.0 (2026-10-19)

- New: Fatigue. Everything you makes you more tired. Resting, eating, and a couple of other things (some not yet built) makes you less tired.
- New: Safehouses. A place to hide away, store some stuff, have a meal and a cold shower. These exist in various districts, and in various degrees of expensive and/or comfortable, and have one or more features. Everyone gets a cardboard box underneath the bridge in Knightsbridge Projects by default so you might wanna find a better place.
- New: Food! There are a couple of locations in the city where you can eat. This reduces hunger and sometimes fatigue as well. 
- Safehouses are discoverable when exploring. Finding one lets you scope it out to find out who lives there, and then break in and steal (some of) their stuff. Later you'll also be able to fight people there (home invasions!)
- New: Mood. Yet another bar to keep an eye on. Getting hurt, failing, etc makes you sad. Winning makes you happy. Drugs can make you very happy, for a while, and then very sad for a very long time.
- Drug effects and interactions: Stimulants will now keep you awake. Some more work has been done to lay the foundation for drug interactions, their effect on mood and fatigue, etc.

## 0.64.1 (2026-10-18)

- Bugfix: Decryption missions could never complete, since the decryption processed lost the mission id on the produced files. Fixed now.

## 0.64.0 (2026-10-17)

- New feature: Data fortresses. These are player owned nodes that can host software, store credits and files, defend itself, and produce things.
- Lots of new mechanics:
- Decryptors can run on a fortress, which takes encrypted data dumps and converts them into components.
- Assemblers build programs from designs and components.
- Daemons: Network Taps steal encrypted data from nodes around your fortress, and Transactional Wiretaps skim credits from nodes around the fortress
- Boosters reduce cycle times, system demands or increase various stats.
- Cloakers disguise the data fortress as a regular node system. 
- Market let's you host a public (or invite-only) marketplace for digital goods, and skim a few percent off the top of every transaction
- Relocators move your fortress around on the grid at set intervals, updating the address as well
- ICE and IDS for data fortresses are not off the shelf, but has to be constructed  and the skill of the programmer determines their strengths
- Leechers are the lifeblood of a fortress: it redirects distributed compute, RAM and storage resources from infected systems to your data fortress
- All of this stuff for fortresses require various skills to set up and craft, also taking a considerable amount of decrypted data dumps to supply it with component files. Setting up and keeping a data fortress alive is generally going to be a cooperative effort.
- New utility program for your deck: Jumper. It adds a "Jump" button next to addresses at various places in the UI, a simple QOL hack

Please note that all the required skills for Data Fortresses are off the market, and will be seeded as loyalty or exchange market purchases in the near future. If you want to test the whole thing out, ask admin and I can set you up with a package.

## 0.63.2 (2026-10-16)

- Internal tooling: Updated effects panel for items/skills

## 0.63.1 (2026-10-16)

- Exchanges now offer at most one data transport job at a time.
- Standings bar shown at exchanges

## 0.63.0 (2026-10-16)

- New mission type: Agitation. Make a run on a system, wake its ICE, then get out so the system locks down.
- New mission type: Extraction. A file is waiting for you on the target system. Pull it, decrypt it and hand it in.
- New mission type: Heist. Raid one to three systems for cash and hand the proceeds in at the desk. You get 10% of what you collected back on top of the pay.
- New mission type: Data transport. You are handed a sealed data container and a coordinate. Carry it there and use Upload sealed data. Your heat is raised while you carry it, so expect a bloodhound. If it beats you, the container is lost.
- Extraction jobs warn you that you will need decryption.
- Exchanges now offer more missions at a time (4 to 8 instead of 2 to 4).
- If a system a job names goes dark, you are assigned a new target of the same grade and type. 

## 0.62.2 (2026-10-16)

- Bugfix: the Tasks screen (and exchange desks) could fail to load for anyone with a mission whose target had gone dark.
- The city map now clearly marks where you are

## 0.62.1 (2026-10-15)

- YOu can paste `run:<id>` in chats to invite someone to your co-op run. Planning screen has a button to copy id. If players are not present at that node, they can copy it instead and jump there.
- A green checkmark next to the addresses in Known Systems for systems you have a full scan of.
- Character sheet now displays drug effects on attributes
- If a mission system dies, you will be assigned a new system

## 0.62.0 (2026-10-15)
- All item cards should now show effects
- Big update! Co-op runs and Data Fortresses
- You can now invite people to join a co-op intrusion run on a system (requires at least level 1 of Coordinated Strike skill). 
- The creator of the strike sets profit sharing options.
- ICE attacks the least stealthy and/or the runner that has caused the most damage, but only one gets attacked each round.
- Rounds are automatic and on time
- Data Fortresses! These are "constructable" nodes on the cyberspace grid, where players can host their own systems, have shared storage areas, run utility programs or producers
- Data Fortresses consume resources (ram, cpu, storage) that you leach from other nodes on the system using a new program called Leechers. 
- If the supply runs short, processes stop and in worst case leave the fortress wide open for raiding
- Cloaked systems will soon be a real thing
More info and documentation to follow

## 0.61.1 (2026-10-15)

- Bugfix: stackable items were all in if you put them up on market. You can now split and choose quantity when putting it up
- Market listings now have a small button in the lower right corner, that copies the id. Pasting it in chat makes a modal that leads to the item. The format is `market:<id>`.
- You can also refer to addresses in chat with `addr:<address>`, which makes the address a copy-to-clipboard link like elsewhere

## 0.61.0 (2026-10-15)

- Tolerance to drugs now wears off faster: 3 points a day instead of 2
- Tolerance and addiction values now targetable by clinics (no rehab clinic created yet)
- Tolerance and addiction values now also targetable by drugs or other items
- New items to treat HP (TraumaSpray, 4-bromothalalomine)
- Public clinic in Downtown to treat HP

## 0.60.0 (2026-10-15)

- New achievements: Vampire, Headhunter, Wanted and Indomitable
- Hunt programs are now called Bloodhounds
- "Known systems" now shows the grade of exchanges
- Loyalty can now be fractional, so a shelf item can cost less than a whole point

## 0.59.0 (2026-10-14)

- Egress now possible in cyberspace combat
- Hunter cooldown: if you were attacked in cyberspace because of Heat, no new hunter will be spawned for 60 minutes
- Chat, bio and other places now have beautiful 9x16 VGA font, for that lovely CP437 ASCII art
- Chat messages can now be 2000 characters long. Shift+Enter for newline
- You can now rescan systems you've already scanned

## 0.58.2 (2026-10-14)
- Some logging and potential bugfixing around cyberspace combat and icebreakers (maybe?) not working as intended
- Cyberspace combat now kills all running programs before attempting to start an icebreaker

## 0.58.1 (2026-10-14)

- Chars have a public profile now
- Profile linked from handle in chat
- Placement jobs replace "Install siphon" with "Place Backdoor"

## 0.58.0 (2026-10-14)

- Chat now exists (in sidebar). "General" is where everyone is by default
- Create your own rooms
- /msg <handle> to send a message
- Websockets via Laravel Reverb implemented for chat and messages
- Achievements now grant +1 standing with everyone
- Hazard stripes for "out of bounds" on the cyberspce map

## 0.57.0 (2026-10-14)

- Testers get a little gift <3
- Tasks & Missions now show an unread badge
- Pathfinders now take coordinates
- Pathfinders now have a traversal_seconds value (the default is 10, the current existing one has 8).
- Extraction could extract hardware, which is weird. Changed now to only extract data.
- If you put a program up for sale it cannot be used any more
- Some bugfixes for edge cases re scanning, hunts, runs etc
- Top bar now shows a heat marker

## 0.56.1 (2026-10-14)
- Markets at exchanges and city locations now have the full UI from the regular market
- View range now displayed in cyberspace screen
- Stash now shows images if available
- Shops in the City and the Exchange stores now look like the market, with search and categories
- The City page is just the map: hover or click a district to see what is there, and to walk, take a pod or explore. The district you are in pulses
- Cyberspace shows your view range; hover or click it to see where it comes from and what it covers
- Items in the stash show their picture when they have one, and scan files show their address

## 0.56.0 (2026-10-13)

- HP and SI can now have fractions, and nothing that touches it rounds to integers any more

## 0.55.1 (2026-10-13)

- Heat now displayed on character sheet

## 0.55.0 (2026-10-13)

- We now have **Critical hits**. Any hit that is above "near miss" has a 2% chance of causing a critical hit, which does 5x the damage. This can be further raised by skills, scripts, drugs etc down the line.
- Raised payout levels for siphons, more at lower levels.
- Hyperfocus and Microexpressions are two new skills, so now we have skills for every base attribute except Muscle. These can only be found as loyalty purchases at exchanges.
- Raised starting creds from 2000 -> 3500
- The playlist now shuffles on play

## 0.54.0 (2026-10-13)

- Minor: Added achievement **The Knowledge**

## 0.53.2 (2026-10-12)

- Bunch of images added (temporarily, I don't have permission to use them) just for feel.

## 0.53.1 (2026-10-12)

- Market, shops and exchange stores now list qflops, qb, storage, power draw, thermal units etc for deck hardware and softare.
- Split the market categories into beter types: Ammo (by calibre), weapons (by type), drugs, implants. RAM and CPUs subcategorized by type/socket
- Same hover-modal on deck board hardware as in market

## 0.53.0 (2026-10-12)

- Encounters can now override the general encounter chance
- Bugfix: Encounter editor swallowed journal edits

## 0.52.0 (2026-10-12)

- The Journal on Tasks & Missions now work, and is targetable by encounters etc (so they can write and invalidate it). See this as "quest notes".
- Jump and Routing Chain boxes are now accordions, closed by default (like Thresholds).
- New button in Jump box: Browse known systems. Brings up a list of all (still valid) systems you've seen, takes filters.

## 0.51.0 (2026-10-12)

- View ranges changed. They now have specific shapes at various levels. The default for new players is vr2. 

```
vr0       vr1       vr2       vr3       vr4
--------- --------- --------- --------- ---------
--------- --------- --------- --------- ---------
--------- --------- --------- ----*---- ---***---
--------- ----*---- ---***--- ---***--- --*****--
----x---- ---*x*--- --**x**-- --**x**-- --**x**--
--------- ----*---- ---***--- ---***--- --*****--
--------- --------- --------- ----*---- ---***---
--------- --------- --------- --------- ---------
--------- --------- --------- --------- ---------

vr5       vr6       vr7       vr8       vr9
--------- --------- --------- ----*---- ---***---
--------- ----*---- ---***--- --*****-- --*****--
---***--- ---***--- --*****-- -*******- -*******-
--*****-- --*****-- -*******- -*******- *********
-***x***- -***x***- ****x**** ****x**** ****x****
--*****-- --*****-- -*******- -*******- *********
---***--- ---***--- --*****-- -*******- -*******-
--------- ----*---- ---***--- --*****-- --*****--
--------- --------- --------- ----*---- ---***---
```

- Network Topology skill now adds +1 view range / level
- Bugfix: There was a bug that jacked you out on a successful egress after injecting a backdoor. 
- Encounters can now happen in cyberspace as well.
- Exchanges now pay double standing and loyalty, except for grade 1 that pays about 20% more instead.

## 0.50.0 (2026-10-12)

- Skills are no longer sold on the Market tab (this is generally moving towards not being a thing down the line, instead focusing on buying stuff at locations and/or exchanges).
- There is a location in Downtown called _Skills R Us_ where you can buy most eidetic imprints (skills).
- Removed prefix "Eidetic Imprint".
- Bug: Kernel Exploitation skill was filed under OPerating Systems, and that is used to seed what OSes systems are running. Fixed now.
- More fixes for run planning screen re what can run and not
- Populating skills that are not avialable at Skills R Us to end up as loyalty purchases at exchanges, in some locations and as loot

## 0.49.2 (2026-10-12)

- The run planning screen now displays scripts as well
- Run screen now correctly displays ram/cpu etc depending on what you select
- Help page removed, it needs quite a lot more writing before it is useful

## 0.49.1 (2026-10-11)

- Missions: Green checkmark next to addresses that are "done"
- Empty sockets on a deck now shows their corresponding type glyph
- Reverted colors for scan files, encrypted data and components since they now have specific glyphs

## 0.49.0 (2026-10-10)

- Toggle sound on/off in the top bar.
- Added a dedlok playlist to the topbar
- Changed how scans work: before you could figure out the grade of a system because scan time scaled with grade. This is now changed so all passes take 1 minute, only the difficulty determines if something is discovered in a pass.
- New icons for programs, hardware, implants, armour, drugs, weapons and more.
- Scan files, encrypted data and components now have their own colours in the stash.
- The run log now displays what (if any) files were pulled out of an extraction run

## 0.48.0 (2026-10-09)

- Grid is now 128x128 instead of 64x64 (it was getting crowded in here)
- All locations, sightings, bookmarks etc have been updated to the new coordinates
- Locators now accepts addresses as input as well
- Tasks & Missions lists the exchanges you have worked for in the last 7 days

## 0.47.0 (2026-10-09)

- New program types: Locators and Pathfinders.
- Locators take a search term (like "exchange, lockjaw, grade >2") and show the direction and number of steps to target.
- Pathfinders do the same, but has the option of "autotraverse" - autopiloting you to your destination at 10 seconds per step. This will be targetable by skills later, to cut down the waiting time.
- Boosted payout for Survey missions, starting at x3 at grade 1 and tapering down to x1 at grade 5.

## 0.46.1 (2026-10-09)

- Listing required skills in the crafting box if you are lacking

## 0.46.0 (2026-10-09)

- Internal tooling fix: Various checkboxes in admin
- Achiements now show the percentage of players who earned them
- Achivement unlocked message now sends
- Updated the names of achivements

## 0.45.0 (2026-10-08)

- New mechanics: Code Crafting. Takes Designs and Components and produces Programs.
- Encrypted Data from extraction runs now yield various component files when decrypted.
- Component files retain their stats from the system they were extracted from (grade, owner, type etc)
- Crafting can produce existing progras (like Icebrakers) or a new type called Scripts. Scripts are one-use programs that provide a stat boost for a short while, and gets deleted after use.
- Code Crafting is skillgated behind the skill Code Crafting. A few supporting skills also seeded.
- Tweak: RAM, Storage and compute are now decimals rather than integers, so that files for instance can take up less space than 1 QB.
- Supporters: Signing up for a membership on Ko-Fi now grants a support badge, unlimited skill queue, and option to upload an avatar on the character screen.

## 0.44.0 (2026-10-08)
- When completing a scan, a scan file is automatically created (killed the old, clunky "export scan" mechanics. 
- "Current processes" button in the topbar shows progress of scans, trips, decryption, training, drugs, withdrawals, job deadlines and market restocks.
- Boxes moved to the right of the cyberspace map if there is space.
- Run briefings now warn about low SI where that would lead to auto-egress on the first turn.
- Auto-egress now states that in the popup notice
- Succesful runs now state "Done" rather than "Egressed".

## 0.43.0 (2026-10-08)
- Address is now in the "Scan complete" message.
- SI passive regen tweaked to 1 SI / 2hrs
- Decryption: Encrypted Data extracted from intrusion runs can now be decrypted (yields Memory Dumps, more types to follow)
- Decryptors (Ghostkey, currentty just for Cinder) + supporting skills now exist
- Decryption queue and progress under Deck view
- Some Exchanges in cyberspace now buy both Scan Files, Encrypted Data and Memory Dumps. Different Exchanges pay differently however.
- Encrypted Data (and its yields) are now dependent on system grade

## 0.42.3 (2026-10-07)

- Tasks & Missions menu now exists. Currently just lists accepted jobs, in the future it will also hold quests and journals.

## 0.42.2 (2026-10-07)

- Buxfix: Survey jobs were missing a check for handing in.
- When acceting an exchange job, the details are now sent in a message.

## 0.42.1 (2026-10-07)

- Added ROuting chain box to cyberspace view

## 0.42.0 (2026-10-07)

- Player-2-player messaging.
- List total and active players 
- Minor tweaks and bugfixes for the encounter editor

## 0.41.0 (2026-10-07)

- Minor SEO/OG fixes
- Name/credit field for alpha testers
- Updated a few locations

## 0.40.0 (2026-10-07)

- Discord notice for new users

## 0.39.0 (2026-10-07)

- New item type: Consumables. Healps HP immediately.
- HP now regenerates over time, and the regen can be targeted by skills, drugs etc.
- New drug family: Medical (for various things that don't fit other categories). Meds have no addiction, but can build tolerance.
- Some drugs are now "combat only", ie they tick on turns rather than seconds so only usable when locked into combat
- While in combat, all the heartbeat stuff in the background (regeneration, withdrawal etc) pauses.
- Added an overdose warning to the drug section on the character screen

## 0.38.0 (2026-10-07)

- Equipment tab on the character screen.
- Skeleton in place for equipment, armor etc.
- Balanced some combat skills
- Training Queue skills are now draggable
- Messages are now polled every 5s, and a sound plays (if the browser allows it) at new messages.
- Lots of tidying up and tightening of the texts and UI for cyberspace screen, scanning, and intrusion runs.
- The "Running programs" modal now lists programs you can start, and a button to start them
- Switched order of run log so latest is always on top (also applies to Hunter logs). 
- Completed scan messages now contain the results of the scan
- Decorated and tidied up the /down screen. 
- Character bio now renders with monospace font, so we can use ASCII art
- Some small status message bugs and labelling issues fixed in scan and runs
- Feature flagged "alpha testing" keys when registering - only people with an alpha key can register

## 0.37.0 (2026-10-06)

- Messages are now full messages. Accordion show titles, click for full message.
- Wallet can now display full transaction history, with a pager for 50 items at a time.
- Added a skill gate for pistols, smgs, rifles and shotguns.

## 0.36.1 (2026-10-05)
- Added some dummy weapons and some weapon skills
- Internal admin tooling only: effect rows in the editors can now be duplicated with one click.

## 0.36.0 (2026-10-05)

- Updated and fleshed out the combat mechanics. Loot, standing losses. Consequences for losing.
- Guns decoupled from ammunition when it comes to damage etc. 
- Combat distance: closing/moving away, ranged vs closed etc.
- Fleeing is now only possible at ranges above 2.

## 0.35.0 (2026-10-05)

- Skeleton for physical combat started.
- Armour and body parts now exist. 
- Damage types, penetration, persistent effects, resistances.
- Opponents now exist as a data structure and in the admin editor.
- Snall guard stuff about travelling, encounters and fights in effect

## 0.34.1 (2026-10-05)

- Internal dev only: the composer sidecar for local dev was missing intl*

## 0.34.0 (2026-10-03)

- Basics of The City now exist. There are districts and locations, you can travel between them.
- Some locations now interactable (clinics, shops).
- Not all locations known from the start, but explorable (chance based). There are also locations not revealable through exploration, but through other means.
- Implant surgery now happens at clinics. Clinics have different skill levels, making it risky to choose a back-alley clinic.
- Cyberspace: A completed trace now cost you standing with the systems owner.

## 0.33.0 (2026-10-02)

- Bounties now exist for real: creds are added to character bounties, growing with heat and grade of systems you made runs on. Nobody can collect it yet, though.
- Wallet now in sidemenu. Every transaction is logged.
- Transfer cash to other characters using the Wallet.
- Standings now in Character sheet. Current standings + every standing "transaction" logged.

## 0.32.1 (2026-09-30)

- Fixed the "Close window" button on the scan-reading screen not working on narrow screens
- Updated Laravel to patch a security advisory.

## 0.32.0 (2026-09-30)

- Heat now triggers "Hunters" jumping you in cyberspace. This triggers a straight up fight, like a run but without the trace, IDS etc.
- Automatically loads the best icebreaker available (that the deck can run) for a Hunter fight
- If an encounter with a Hunter is won, gain some creds and some heat disappears. Lose, and take damage (and lose a chunk of heat as well).
- Hunters have "grades" that are relative to the amount of Heat you have.

## 0.31.0 (2026-09-29)

- Minor rework for OS on systems you make a run on. Added skill `Kernel Exploitation` that gives a general boost against all OSes. OS skills repriced.
- New skill `Signature Scrambling`, to decrease the risk of Ensnare.
- Two new expansion cards: `BlackBox II` and `Pandora SC-652`, both boosting icebreaker damage.

## 0.30.0 (2026-09-29)

- More tuning of ICE damage.
- Big rebalancing of many, many stats, attribvutes and derived values for, hopefully, more meaningfull skill modifiers.
- Rebalanced Reactive Firewalling, Mitigation Protocols and Neural Sheathing (dead at level 1).
- SI slowly regenerates over time.
- Added skill `Opaque Routing Protocols`, for creating sturdier hop chains to protect against tracing.

## 0.29.0 (2026-09-29)

- `ORACLE` scanner buffed to +19% chan checks, and +25% scan speed, but is not available on market (only through loyalty stores).

## 0.28.0 (2026-09-29)

- Reduced the gradient for how much ICE damages you (higher grades were not survivable).
- Skills added to further reduce ICE damage taken (`Reactive Firewalling`, `Defensive Compartmentalization`).
- Added a daemon (`Spectral`) that also reduces SI damage taken from ICE.
- `Trauma Recovery` skill to reduce waiting time on the "kill" screen.
- `Showmanship` skill to increase standing gains when performing missions etc.

## 0.27.0 (2026-09-28)

- Actual implementation of storage, QB etc. Software, files and imprints now take up space. All decks got internal storage. 
- Reject "saves" to storage if storage is full (e.g files from an `Extract` operation).
- A deck over storage limit will not jack in until you make room.
- Linked imprint QB storage to their rank. Higher rank skills take up much more space on disk.
- Changed the New Character settings so no attribute can be below 10 (no fully dumpable stats).
- Fixed a bug where `Mitigation Protocols` actually reduced your icebreaker strength instead of increasing it.
- Cleaned up the effects on `Advanced Routing`, and tweaked the effects for `Power Management`.

## 0.26.1 (2026-09-28)

- Introduced stacking penalties for programs of the same type. Diminishing returns up to 6, the 7th gives 0% increase.
- Recuced mission credits reward by a factor of 10, since it was wildly unbalanced.
- Linked `Heat` to the grade of a system. Burns give more heat than a completed trace.

## 0.26.0 (2026-09-28)

- Implemented support for `Services` at `Exchanges`.
- Lockjaw exchange now has a service that takes away heat for loyalty points.

## 0.25.1 (2026-09-28)

- Halved the nightly decay of standings 
- Tweaked standing payouts for higher grade jobs. 

## 0.25.0 (2026-09-28)

- In the prep screen for a run on a system you now get to pick "loadout". This is saved as default for your next run.
- First drug introduced: `Rewire`, increases SI regeneration while reducing Intellect and Focus, for a short time.
- Changed the mechanics for how ICE guards the core of a system. If ICE is activated, extract/backdoor/burn operations pause until ICE is defeated (or you egress or are flatlined etc).
- The IDS systems now make a detection check every turn, including during the extraction phase. Higher chance of getting detected over long runs.
- Tweaked the curve for IDS detection scaling at higher grade systems, to make it possible to remain undetected.
- Added skills `Clandestine Intrusion Methods` and `Polymorphic Code` to increase stealth options.

## 0.24.0 (2026-09-28)

- New skills: `Attack Vectors` (increase damage of your Icebreakers), `Frational Siphoning` (increase siphon payouts), `Noise Seeding` (increase Heat decay), `Memory Optimization` (more RAM), `Power Management` (increases power output at the cost of higher thermal load).

## 0.23.1 (2026-09-28)

- Don't reload page on every purchase on the Market.
- Popup notifications gets removed after a delay

## 0.23.0 (2026-09-28)

- Send a combined report of all your siphons each night.
- `Training Queue` now shows how long each entry will take to complete.

## 0.22.1 (2026-09-28)

- Info-modal on imprints on hover.
- Lots of SQL optimizations, to greatly reduce page loading time and DB overhead.

## 0.22.0 (2026-09-28)

- Mark items you already have in stash on the market, and mark skills you've alredy injected.
- `Bounty` warning sent once a week.

## 0.21.2 (2026-09-28)

- Receive a message when losing a backdoor to a system.

## 0.20.0 (2026-09-24)

- `Loyalty store` now exists at exchanges.

## 0.19.5 (2026-09-24)

- Minor bugfix: A few itemn spec lines and labels showed stubs instead of titles.

## 0.19.4 (2026-09-24)

- Display game version under the logo

## 0.19.3 (2026-09-24)

- Added Ko-Fi button to the main page.

## 0.19.1 (2026-09-24)

- Some minor language changes

## 0.19.0 (2026-09-24)

- `Missions` now exist. Agents at various `Exchanges` (exchanges seeded on the grid) hand out missions. Complete for credits, standing increase and loyalty points. Exchanges have grades, which determines difficulty and reward.
- Every player has their own mission board, refreshed every hour.
- Message when a scan file goes stale.
- Message when someone buys one of your listed items.

## 0.18.0 (2026-09-24)

- `Scan files` are now created upon completed scan. Can b e used to sell it to others, as "payment" for certain missions, or to transfer knowledge to other players. Consumed on "read".
- `List on market` skeleton, for selling items to other players.

## 0.17.0 (2026-09-23)

- Icebreakers now exist. Various grades, with various requirements and bonuses.
- Bugfix: `Jacking in` failing near the entry portal

## 0.16.0 (2026-09-23)

- Systems on grid now owned by entities. Various entities created.
- Exchanges added to grid (non-hackable interactable systems for these "mission-giving" entities)
- Renamed skillbooks to Eidetic Imprints

## 0.15.1 (2026-09-23)

- Some work on drugs: simple tolerance, overdose mechanics, effects etc.
- Training Queue now shows total time, skills have the skill modal on hover.
- Opening a message marks it as read
- Added a logo to the front page

## Earlier versions
- Earlier version changelogs not in this document.
