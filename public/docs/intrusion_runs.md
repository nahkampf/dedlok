# Intrusion Run Update
Here's a pretty big update, as it completely changes how Intrusion Runs are done in the game. It is now much more interactive, comes with more surprises and requires a bit more exploration and tactics to overcome. I think it also makes co-op runs much more engaging, as different runners can take different roles or coordinate their actions in various ways.

## The run as a flowchart
The systems you will attempt to break into now have a number of subsystems connected in various ways, like a flowchart. The lower grades are simple, while the higher grades offer several paths to the target (the core), with various drawbacks and advantages. But first, let's talk about the subsystems and what they do:

### Entrypoint
This is the public-facing interface for the system, and where you first enter when you are making a run. The entrypoint leads to the rest of the systems. The entrypoint is also an _egress_ point - when you want to exit the system you can go there to attempt your egress (as you normally can't do that from just any subsystem). An entrypoint is also where you can attempt to _flood_ the system with data.

### Maintenance port
Some systems have Maintenance ports - they usually sit near the core and allow runners to egress the system without having to backtrack to the entrypoint. Systems that you hold a backdoor on also allow you to choose the maintenance port as an entrypoint, cutting the distance to the core. Egress from a Maintenence port is also easier than from an Entrypoint.

### Core
This is the main target of a run - where you install backdoors, burn a system or extract data. Cores are usually protected by _ICE_, and have an _Operating System_ (the higher your skill in that specific Operating System, the higher your chance of a successful action).

### Switch
A switch is a fork in the connections, leading to two or more subsystems. 

### Conduit
These are essentially empty nodes, acting as links between two subsystems. No actions here.

### Internal Relay
Internal Relays handle network traffic and routing for the system. This can be exploited - running a _Loopback_ script here can buy you and your crew more time from a tracing attempt. A Loopback on an internal relay is active as long as:
- You stay at the relay node
- The script is still active
- If passes its' hold roll (script strength + Loopback Routing skill vs a value set by the system grade)

### Firewall
A firewall is essentially a blocker: In order to pass through it you have to breach it, which can take valuable time and create so much noise that you get detected. When breached, a Firewall is open to any runner in a run (as in co-op runs).

### Data Vault
A data vault can contain credits or data that can be extracted. These are typically less than what the core holds, but can be an extra source of income if the run is going well.

### Traps
A trap is a subsystem designed to trap or hinder an intrusion on the system. They can trigger upon entering them or interacting with them. They have various effects:
- **Alarm** - Creates noise, which may make the IDS notice.
- **Trace Jump** - Increases the trace on you
- **Ensnare** - Locks you at the trap a turn or two.
- **Node Disconnect** - Destroys itself before you arrive, severing the connection on this path (potentially forcing you to take another route to the core).

### Honeypots
Data Vaults might be honeypots - if attempting an _Extract_ on them, they go off and cause an headache for the runners. Can be disarmed while standing on them.

### Log Server
A run where the alert went off can cause standing drops for the players and a system lockdown after the run. When the system is on alert, a runner can wipe the Log Server to remove the alert standing loss and lockdown.

### Security Hub
The security hub controls the system's alert state. If you manage to traverse to one without setting off the alarm you can subvert it, which turns off the system's ability to raise the alert. ICE, traps and honeypots can still wake for other reasons but without a security hub the system can't communicate an alert to the rest of its subsystems and security systems. A system where the alert has already gone off can't have its security hub subverted.

### ICE Controller
A subsystem that sits near the core. If an alert goes off this subsystem will spawn new Sentry ICE every three turns. It can be shut down (preferrably before an alert has raised).

## Mechanics
Making a run on a system now means entering at the entrypoint (or a maintenance port), exploring and traversing the node tree, overcoming the obstacles on the way and reaching the core to perform one or more actions. An intrusion run is now much more open-ended in terms of what you can do and the outcomes, so we no longer have the "Purposes" on the running screen - it is quite likely that you intended to go in to install a siphon but instead find a few data vaults to raid on the way for instance.

### Noise
Some actions during an intrusion run cause _noise_. Any system with an IDS in place has a threshold for when this noise trips the alert. Traversing a node causes some - but not much - noise, while other actions such as attacking ICE, springing traps, extracting data or failing a roll cause more. Holding (ie skipping your turn) doesn't cause noise, instead it slightly reduces the accumulated noise. If you have a strong trace network and have caused a lot of noise, holding still for a few turns might be a wise strategy.

If a system has an IDS, there will be a progress bar that shows how close you are to tripping it. A system without an IDS will just show total noise generated.

### Scanning
Scanning a system will reveal some of the same things you're used to: 
- Grade
- Operating System
- IDS
- ICE
- Tracer

But it can now also reveal the layout of the system, how many paths to the core, and how many moves the shortest path requires, if there are any identified traps and the total number of subsystem nodes on the system. Scanning is safe - it doesn't trip any alarms or hostile actions.

### Exploring
While scanning a system is the safest approach, you can also go in blind and explore the system, reacting to what you find. An unscanned system will just show you the entrypoint, its connections and unknown nodes. These can either just be traversed to (with the risk of tripping traps etc), or mapped using Nmappers.

### Nmap
A standard tool for any runner or system operator is the nmap - the network mapper. This reveals one (or more, depending on what nmapper you're running) nodes down the tree - what node type and what connections it has. Running nmap is an action and takes up a turn, and also causes noise. Some more advanced nmappers can perform _Deep nmap_, that can also reveal concealed or obfuscated systems like Traps or Honeypots, and some variants of nmap software might reveal more than one system or one step.

### Move
Traversing from one subsystem to the next one takes a turn per step. Arriving at some subsystems may cause effects (like a Trap activating or noise reaching the alert threshold).

### Egress
Egress (exiting a run) can normally only be performed at the _Entrypoint_ or a _Maintenance Port_. 

### Egress Tunnel
Scripts of the type Egress Tunnel can create an "exit" from any subsystem node. The runner that ran an Egress Tunnel script has to stay put at that node or else the Tunnel will collapse. Cordons can't cut a node with an active Egress Tunnel. 

### Crash Jackout
An emergency measure where you physically rip the cable out of your neural socket to unplug from Cyberspace. This can cause severe neural damage and should be avoided, but there can be situations where this is your only reasonable option because the other options are worse, or you get stuck in a system with no other way out. A crash jackout can be performed from anywhere in a system.

### Breach
An action on a _Firewall_ node. Roll to overcome the Firewall and open it.

### Subvert
Can be performed at Security Hubs. This is an OS roll, failure generates much noise.
-# See _Security Hubs_ for mechanics.

### Loopback
Can be performed at Internal Relays (if you have a loopback script). This is an OS roll, failure generates much noise.
-# See _Internal Relay_ for mechanics.

### Disarm
If you have discovered a _Trap_ or _Honeypot_ you can attempt to disarm it. For this you need to be adjacent to the trap/honeypot node.

### Wipe
Can be performed at Log Servers. This is an OS roll, failure generates much noise.
-# See _Log Server_ for mechanics.

### Shut Down
Turns an ICE Controller off (no more Sentries can be spawned from it).
-# See _ICE Controller_ for mechanics.

### Extract
Can be performed at _Data Vaults_ and the _Core_. Extract credits and/or data from the subsystem. Data Vaults need no roll, but the Core does. A _Honeypot_ gets triggered on Extract.

### Attack
Attacks ICE at the current subsystem.

### Flood
Attempts to overpower the system with a flood of incoming data. This can be performed at _Entrypoints_ and _Maintenance Ports_.

### Core actions
- **Attack** - destroy the ICE guarding the Core.
- **Install** - Plant a backdoor (siphon or routing hop). Both count as backdoors for the purpose of entering via the maintenance ports later.
- **Burn** - Destroy the system.
- **Leech** - For fortress leechers.

## Active Defenses
A system protects itself mainly using Firewalls and ICE. ICE has four basic variants:
- Core ICE - defends the Core, and has to be overcome in order to take Core actions (if it has woken up)
- Barrier ICE - can be present at some Firewalls, and is stationary there. It is awake at start of run, meaning it will attack runners stepping onto it.
- Sentry ICE - If woken up will attempt to intercept the closest runner, by moving on the node tree. Sentries can be _Slow_ (moves 1 step) or _Fast_ (moves 2 steps). Wakes up on a failed stealth roll when you arrive at their node, or on an alert.
- Cordon ICE - Some Sentries have the Cordon ability. If woken up will attempt to sever connections between the runners and the egress points. Cordon can destroy Conduits, Switches, Internal Relays and Maintenance Ports, leaving the runners trapped in the system. A Cordon will indicate which subsystem it is attempting to sever, and a countdown timer of 2-3 turns will start. If Cordon is attacked before the timer runs out the timer will pause while the Cordon engages the runners instead. If Cordon is not defeated, the timer continues. 

## Co-op runs
Higher grade systems often more or less require coordinated effort from a crew. On the planning screen you can invite other runners to join a run, and the screen will display their loadout and if they are ready to go.

### Trace network
The planning screen will also display all runners who joined, who own backdoor routing networks (ie the systems they have placed routing backdoors on). The owner can select the best network for the run, and all participating runners will be routed through that network. 

### The turns
Each turn has a 30 second countdown timer. If participating runners have not taken an action within that timeframe the default action (varies depending on subnode type) will then be performed for them. All actions will then be resolved at the end of a turn (ie when all runners have taken an action). If you have taken an action you will be shown a "waiting" screen while waiting for the other players to take their actions. The log file will then state what transpired during the turn.

### Coordination & Tasks
When performing co-op runs on higher grade systems with a crew to help you out you have many options for how to go about it. The stealthiest runner may want to try to move to a security hub and disable it, while the other runners just hold. One runner might be the mule - with lots of storage to spare they are the one to perform the extracts. Resilient characters might want to bait or fight Sentry ICE, so that others can slip past at another path.
