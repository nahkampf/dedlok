<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody owns every machine on the grid.
 *
 * A system has always had a name; what it has not had is an OWNER, and the two
 * are different things. The name is what the machine calls itself; the owner is
 * who answers for it — and the owner is the half that other systems will one
 * day care about. CYBERSPACE.md already hangs three things off it: a completed
 * trace costs you standing with the owner's faction, a player node is owned by
 * the player, and names are meant to combine by owner as well as by type.
 *
 * A TABLE AND NOT A STRING, for the reason archetypes are a table: an owner id
 * ends up written into thousands of system rows, so it has to be a thing that
 * can be pointed at rather than a word that can be spelled two ways. Faction
 * standing keyed by varchar is the version that falls apart the first time
 * somebody fixes a typo.
 *
 * THEY ARRIVE BY MIGRATION, like the archetype roster and for the same reason.
 * A seeder holding this list would be a second source for content the table
 * owns, and the one that wins on a re-seed. WORLDBUILDING.md is where a human
 * edits the roster; a new corp is a new migration until there is an editor.
 *
 * The eight named manufacturers at the top of WORLDBUILDING.md are deliberately
 * NOT here. They exist to brand hardware — a Kurogane deck, a Hosaka deck — and
 * making them node owners as well would conflate who BUILT a thing with who
 * RUNS it. Easy to add later if the world wants it; hard to unpick afterwards.
 */
return new class extends Migration
{
    /**
     * The roster, straight out of WORLDBUILDING.md.
     *
     * @return list<array{string, string}>
     */
    private function corps(): array
    {
        return [
            ['Apex Dynamics', 'Prosthetics, augmentations and cybernetics.'],
            ['Vertex Logistics', 'Global freight.'],
            ['Oasis Bio-Tech', 'Agricultural tech.'],
            ['Blackwood Mutual', 'Private banking and asset liquidations.'],
            ['Amaterasu Digital Networks', 'Satellite networks.'],
            ['Renhou Bio-Pharmaceuticals', 'Consumer grade medicine.'],
            ['Ryujin Consumer Robotics', 'Service robots and industrial robotics.'],
            ['Kaage Robotics', 'Industrial robotics.'],
            ['Yuzo Micro-Surgical', 'Plastic surgery.'],
            ['Hanwool Heavy Electric', 'Power grids and battery cells.'],
            ['CHeong-san Agritech', 'ALgae farms and synthetic protein.'],
            ['Shin-Yeong Orbital', 'Commercial satellites.'],
            ['Mirae Neuro-Link', 'Cybernetics.'],
            ['Baek-do Logistic', 'Global freight.'],
            ['Shenzhou Orbital', 'Secure satellite communications.'],
            ['Longhai Cybernetics', 'Consumer-grade cybernetics for the asian market.'],
            ['Baozhen Financial', 'High-frequency trading.'],
            ['Tiangong Biotech', 'Stem cell therapies and organ growing.'],
            ['Jinhai Transit', 'Maglev train systems.'],
            ['AeroLuz Mobilidade', 'Sky tais and automated transport.'],
            ['Rede Sertão Alimentos', 'Cheap mass-produced synthetic rations.'],
            ['Prisma Mídia & Transmissão', 'Mass media networks and advertisment.'],
            ['Servicios Globales de Rastreo', 'Private surveillance & security.'],
            ['OmniSeguridad', 'Drones for law enforcement.'],
            ['Société Générale de Données', 'Data brokerage.'],
            ['Aéro-France Logistique', 'Automated transport dirigbles.'],
            ['Zuiderzee Hydro-Engineering', 'Flood barriers and flooding mitigation consulting.'],
            ['Krupp-Vanderlei Shipping', 'High-value goods shipping.'],
            ['Nieuwe Horizon Mining', 'Deep-sea mining.'],
            ['Nordic Capital Group', 'Private banking.'],
            ['Valhalla Virtual Systems', 'VR entertainment systems.'],
            ['Groupe des Systèmes Militaires SA', 'Military hardware and SIGINT systems.'],
            ['SveaCom', 'Fibreoptic infrastructure and networks.'],
            ['Cherny-Svyaz Telecom', 'Hardened and encrypted communications technology.'],
            ['Aethelgard Metallurgy', 'Produces high-grade titanium and carbon-fiber alloys for combat chassis.'],
            ['Kuroda Textile & Mesh', 'Manufactures bullet-resistant Kevlar weaves and smart-fabric clothing.'],
            ['Vostok Mining Syndicate', 'Operates automated deep-crust lithium and rare-earth extraction rigs.'],
            ['Aeterna Weave Systems', 'Synthesizes light-bending optical camouflage materials for military stealth suits.'],
            ['Hidalgo Ore Extraction', 'Controls strip-mining operations across depleted South American red zones.'],
            ['Kwang-Sung Micro-Thread', 'Produces ultra-fine conductive threads used in neural-weave jackets and smart textiles.'],
            ['Yamashiro Heavy Forgings', 'Drops massive industrial press-forged components for arcology frameworks.'],
            ['Neva Slag & Steel', 'Recycles scrap metal from crumbling mega-cities into low-tier structural beams.'],
            ['Tarsus Petrochemicals', 'Processes synthetic polymers and fire-retardant industrial plastics.'],
            ['Lumière Haute-Couture', 'High tech clothing and accessories for the ultra-wealthy.'],
            ['Veldhoven Wafer & Alloy', 'Precision metallurgic production for microelectronics.'],
            ['Shin-Etsu Silicon', 'Producer of intermediate parts and chips for cyberdeck manufacturers.'],
            ['Li-Wei Rare Earths', 'Refines raw monazite sand into high-purity battery compounds.'],
            ['Akira Weave Works', 'Produces heat-dissipating thermal fabrics worn beneath heavy exo-suits.'],
            ['Mashpriborintorg', 'Import-export of high tech discrete electronic components.'],
            ['Agroprom', 'Produces industrial agricultural machinery.'],
            ['Weyland-Yutani', 'Zaibatsu that is diversified into mining, production and consumer goods, and has a small bioweapons division.'],
            ['Tyrell Corp', 'Produces android models for various jobs off-world.'],
            ['Aperture Science', 'Performs testing.'],
            ['Cyberdyne Systems', 'Military hardware and systems.'],
            ['Sable & Roe Actuarial', 'Life insurance priced off your implant telemetry.'],
            ['Kestrel Correctional Group', 'Private prisons and parole-monitoring anklets.'],
            ['Vireo Recruitment', 'Indentures skilled labour to whoever is paying that quarter.'],
            ['Halcyon Rest Services', 'Cremation, composting and discreet body disposal.'],
            ['Meridian Arcology Management', 'Landlord to eleven million people who never meet it.'],
            ['Grayfen Construction', 'Throws up housing blocks faster than they can be inspected.'],
            ['Ninth Circle Security', 'Private military contractors with an unhelpful sense of humour.'],
            ['Courier-9', 'Same-hour physical delivery for things that must not touch a network.'],
            ['Stillwater Desalination', 'Sells drinking water back to the coastal cities.'],
            ['Aureus Credit Bureau', 'Decides what your life is worth to a lender.'],
            ['Ivory Registry', 'Identity verification, and the quiet business of erasing it.'],
            ['Cold Harvest Cryonics', 'Freezes the rich and bills their estates for storage.'],
            ['Pallas Asteroid Holdings', 'Rare-metal extraction from near-earth rocks.'],
            ['Helion Fusion Works', 'Operates the tokamak that keeps a whole seaboard lit.'],
            ['Verdant Column Farms', 'Stacked hydroponics inside repurposed office towers.'],
            ['Marrow & Kin Bio-Logistics', 'Moves organs, blood and marrow on ice, fast.'],
            ['Lethe Memory Clinics', 'Sells you the removal of a memory, with a waiver.'],
            ['Somnus Sleep Industries', 'Compressed-sleep pods for people working double shifts.'],
            ['Fairweather Atmospherics', 'Cloud seeding, hail suppression, and deniable droughts.'],
            ['Ashgrove Reclamation', 'Landfill mining and the slow sorting of the last century.'],
            ['Bright Verge Media', 'Owns nine networks and pretends they compete.'],
            ['Sunken Choir Entertainment', 'Full-immersion serial drama with real consequences.'],
            ['Fortuna Rho', 'Gambling houses, physical and otherwise.'],
            ['Palisade Legal Automation', 'Contract law without a single human lawyer.'],
            ['Ostrava Chemical Works', 'Industrial solvents, and things adjacent to them.'],
            ['Zlatnik Pneumatics', 'Compressed-air tooling for heavy assembly.'],
            ['Wislana Steelworks', 'Structural steel for anything that has to stand up.'],
            ['Hellenic Deepwater Salvage', 'Recovers what sank, and occasionally what was sunk.'],
            ['Bosphorus Transit Authority', 'Runs the strait and everything that pays to cross it.'],
            ['Anatolia Gene Foundry', 'Custom crops, custom livestock, custom else.'],
            ['Zagros Petro-Synthetics', 'Fuel for machines nobody has retrofitted yet.'],
            ['Levant Optics', 'Precision lenses and targeting glass.'],
            ['Al-Rimaya Aerospace', 'Long-endurance surveillance drones.'],
            ['Khaleej Sovereign Holdings', 'A fund large enough to be a weather system.'],
            ['Sindh Water Concern', 'Owns the aquifer under four cities.'],
            ['Bharat Neural Labs', 'Mass-market neural interfaces, cheap and not entirely safe.'],
            ['Deccan Compute Estates', 'Datacentres cooled by an entire redirected river.'],
            ['Ganga Bio-Remediation', 'Cleans rivers, slowly, on a government contract.'],
            ['Nusantara Marine Farms', 'Fisheries and kelp at industrial scale.'],
            ['Selat Freight Combine', 'Container shipping through the straits.'],
            ['Mekong Power Cascade', 'Hydroelectric dams, and the politics attached to them.'],
            ['Truong Son Assembly', 'Contract electronics assembly, no questions asked.'],
            ['Manila Data Sanctuary', 'Offshore data havens with agreeable jurisdiction.'],
            ['Krungthep Prosthetic Works', 'Affordable limbs with a three-year waiting list.'],
            ['Southern Cross Grid', 'Continental power transmission.'],
            ['Wattlecorp Agritech', 'Drought-engineered grain for a continent that needs it.'],
            ['Tasman Deepwater', 'Offshore rigs, and the platforms nobody visits.'],
            ['Laurentide Hydro', 'Sells winter electricity to half a continent.'],
            ['Northgate Insurance Syndicate', 'Reinsures what nobody else will touch.'],
            ['Ciudad Perpetua Servicios', 'Municipal services for a city that outgrew its government.'],
            ['Cordillera Lithium', 'Brine extraction from high-altitude salt flats.'],
            ['Naija Orbital Freight', 'Cheap, unglamorous access to low orbit.'],
            ['Lagos Mesh Networks', 'Community mesh infrastructure that became a monopoly.'],
            ['Rift Valley Geothermal', 'Taps the fault line for baseload power.'],
            ['Table Bay Refining', 'Processes whatever the deep-sea rigs bring up.'],
            ['Sahel Solar Concern', 'A photovoltaic field the size of a small country.'],
            ['Ubuntu Medical Systems', 'Clinic automation and triage software.'],
            ['Anansi Data Weaving', 'Data brokerage with an unusually good reputation.'],
            ['Kilimanjaro Cold Chain', 'Refrigerated logistics for medicine, and for worse.'],
            ['Blackfen Waste Solutions', 'Disposes of what other disposal firms refuse.'],
            ['Concordat Archives', 'Stores records for institutions that no longer exist.'],
            ['Umbrella Health', 'Pharmaceuticals, biotech, and an unusual number of lab accidents.'],
            ['Soylent Nutritional', 'Reprocessed protein products. Ingredients proprietary.'],
            ['Buy\'n\'Large', 'Everything, everywhere, from one shopfront.'],
            ['Rossum & Sons', 'Synthetic labour, and the leasing thereof.'],
            ['Encom Interactive', 'Legacy computing that still runs things nobody can replace.'],
            ['Sirius Cybernetics', 'Consumer robotics with famously irritating personalities.'],
        ];
    }

    public function up(): void
    {
        Schema::create('corps', function (Blueprint $table): void {
            $table->comment('The companies that own machines on the cyberspace grid. Arrive by migration like the archetype roster, because their ids are written into system rows.');

            $table->id()->comment('Primary key. Written into systems.owner_id, so it is permanent.');
            $table->string('name', 64)->unique()
                ->comment('What the corp is called. Unique: two corps with one name is one corp with a typo.');
            $table->string('business', 160)
                ->comment('What they do, in a line. Flavour on a system card; carries nothing mechanical.');
            $table->timestamps();
        });

        $now = now();

        DB::table('corps')->insert(array_map(
            static fn (array $corp): array => [
                'name' => $corp[0],
                'business' => $corp[1],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->corps(),
        ));

        Schema::table('systems', function (Blueprint $table): void {
            // Nullable, because THE PORTAL HAS NO OWNER. It is the one system
            // that is none of the things a system is, and inventing a company
            // to run it would be a fact nobody asked for.
            //
            // restrictOnDelete rather than a cascade: deleting a corp that
            // still runs machines should fail loudly, not quietly burn every
            // node it owned.
            $table->foreignId('owner_id')->nullable()->after('type')
                ->comment('Which corp runs this machine. NULL for the portal, which nobody owns.')
                ->constrained('corps')->restrictOnDelete();
        });

        /*
         * A contact remembers the owner too, as a STRING and not a pointer.
         *
         * Everything on a contact is a snapshot — what the machine called
         * itself, what kind it was, what it answered to — and the owner is no
         * different. Storing an id would tie the memory to a corp row and make
         * the memory wrong the day that row changed; storing the name keeps it
         * a record of what the player saw, which is the only thing a contact
         * has ever been.
         *
         * Nullable, because the portal has no owner and because a sighting
         * taken before this column existed has nothing to put in it.
         */
        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->string('owner', 64)->nullable()->after('type')
                ->comment('Who was running it when they looked, as a name rather than a pointer. NULL for the portal, which nobody owns.');
        });

        // Give the machines already standing an owner, rather than making
        // anyone re-seed a world that is otherwise fine. Spread evenly; the
        // generator does the same for everything instantiated after this.
        $ids = DB::table('corps')->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        /*
         * ONE UPDATE PER CORP, not one per system.
         *
         * The obvious loop is an UPDATE for each of several thousand rows,
         * which is fine against a socket on the same machine and minutes of
         * pure latency against a database on the far side of the internet —
         * which is what this application actually talks to. Bucketing the ids
         * by the corp they were dealt turns that into one statement per corp:
         * a hundred-odd round trips instead of thousands, for the same result.
         */
        $buckets = [];

        DB::table('systems')->where('type', '!=', 'portal')->orderBy('id')
            ->chunkById(1000, function ($systems) use ($ids, &$buckets): void {
                foreach ($systems as $system) {
                    $buckets[$ids[array_rand($ids)]][] = $system->id;
                }
            });

        foreach ($buckets as $owner => $systems) {
            foreach (array_chunk($systems, 1000) as $slice) {
                DB::table('systems')->whereIn('id', $slice)->update(['owner_id' => $owner]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->dropColumn('owner');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropForeign(['owner_id']);
            $table->dropColumn('owner_id');
        });

        Schema::dropIfExists('corps');
    }
};
