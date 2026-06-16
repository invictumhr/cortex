// Cortex UI translations. Keys are flat with dotted namespaces; placeholders
// use the {name} syntax and are expanded by useT().t(key, vars).
export const translations = {
    hr: {
        'app.brand': 'Cortex',
        'app.tagline': 'AI multi-model brainstorming platforma',
        'app.langToggle.title': 'Promijeni jezik sučelja',

        'index.heading': '🧠 Cortex Boardroom',
        'index.adminLink': '⚙ Admin',
        'index.newBoardroom': 'Novi boardroom',
        'index.titlePlaceholder': 'Naslov ili tema rasprave',
        'index.descriptionPlaceholder': 'Opis (opcionalno)',
        'index.contextPlaceholder': 'Poznati kontekst — data model, postojeće stanje (opcionalno)',
        'index.constraintsPlaceholder': 'Tvrda ograničenja — koja prijedlozi ne smiju kršiti (opcionalno)',
        'index.architectLabel': 'Architect',
        'index.architectDesc': 'Model dizajnira panel uloga skrojen za temu (umjesto ručnog odabira)',
        'index.strongLabel': 'Strong',
        'index.strongDesc': 'Panel na flagship modelima (skuplje, jače)',
        'index.architectHint': '🧩 Architect će sam dizajnirati panel uloga iz naslova i opisa — ručni odabir persona je isključen.',
        'index.personasLabel': 'Persone ({n} odabrano)',
        'index.submit': 'Pokreni boardroom',
        'index.chatsHeading': 'Tvoji chatovi',
        'index.noChats': 'Još nema chatova — pokreni prvi boardroom.',
        'index.chatMeta': '€{cost} · {n} por.',

        'show.pause': '⏸ Pauziraj',
        'show.resume': '▶ Nastavi',
        'show.empty': 'Pošalji prvu poruku — boardroom kreće i vrti se dok ga ne pauziraš.',
        'show.typing': '{name} razmišlja… (krug {round})',
        'show.pausedHint': '⏸ Rasprava je pauzirana — „Nastavi" je vraća, ili pošalji novu poruku.',
        'show.sendFailed': 'Slanje poruke nije uspjelo.',

        'panel.totalCost': 'Ukupni trošak',
        'panel.meta': '{messages} poruka · {tokens} tokena · krug {round}',
        'panel.personasHeading': 'Persone u boardroomu',
        'panel.noModel': 'bez modela',
        'panel.adminLink': '⚙ Cortex Settings (Admin)',

        'input.placeholder': 'Poruka boardroomu — Enter šalje, Shift+Enter novi red',
        'input.urlPlaceholder': 'https://primjer.com/clanak',
        'input.send': 'Pošalji',
        'input.sending': '…',
        'input.attachImage': 'Priloži sliku',
        'input.attachUrl': 'Priloži URL',
        'input.powershell': 'PowerShell naredba',

        'login.title': 'Prijava',
        'login.subtitle': 'Nastavi u svoj AI boardroom.',
        'login.email': 'Email',
        'login.password': 'Lozinka',
        'login.remember': 'Zapamti me',
        'login.forgot': 'Zaboravljena lozinka?',
        'login.submit': 'Prijavi se',
        'login.submitting': 'Prijavljujem…',

        'guest.headline': 'Vijeće AI stručnjaka za tvoje najteže odluke',
        'guest.lead': 'Boardroom AI persona — svaka na svom modelu — raspravlja tvoju temu iz više kutova, onako kako je jedan model ne bi.',
        'guest.feature1': 'Više AI modela raspravlja, ne samo jedan',
        'guest.feature2': 'Scribe sažima tijek, Chair donosi odluku',
        'guest.feature3': 'Strukturirana sinteza s prioritetnom matricom',
        'guest.footer': 'Osobni alat za višeperspektivni AI brainstorming',

        // Sidebar (chat list)
        'sidebar.newChat':  'Novi boardroom',
        'sidebar.empty':    'Još nema rasprava',
        'sidebar.today':    'Danas',
        'sidebar.yesterday': 'Jučer',
        'sidebar.earlier':  'Ranije',
        'sidebar.admin':    'Admin',
        'sidebar.expand':   'Proširi',
        'sidebar.collapse': 'Skupi',

        // Welcome screen (Chats/Index)
        'welcome.greeting':       'Dobrodošao, {name}.',
        'welcome.greetingAnon':   'Pokreni raspravu',
        'welcome.lead':           'Multi-model AI panel — svaka persona na svom modelu, jedna sinteza na kraju.',
        'welcome.titlePh':        'Naslov (opcionalno)',
        'welcome.topicPh':        'O čemu boardroom treba raspravljati? (možeš i kao prvu poruku nakon starta)',
        'welcome.startBtn':       'Pokreni raspravu',
        'welcome.balance':        'Stanje: €{balance}',
        'welcome.topupBtn':       'Nadoplati da krenu',
        'welcome.recent':         'Posljednje',

        // Init mode tabs
        'mode.quick.label':       'Brzi start',
        'mode.quick.hint':        'Odaberi samo broj agenata',
        'mode.custommodels.label': 'Custom modeli',
        'mode.custommodels.hint':  'Odaberi točne AI modele',
        'mode.custom.label':      'Custom',
        'mode.custom.hint':       'Odaberi personu + model',

        // Quick panel
        'quick.label':            'Koliko AI agenata u panelu?',
        'quick.hint':             'Modeli i persone se biraju nakon prve poruke — sustav čita složenost i odabire odgovarajuće uloge + različite AI modele.',
        'quick.strongLabel':      'Strong tier',
        'quick.strongHint':       'Forsiraj flagship modele (Opus 4.7, o3, Grok 3, Gemini Pro) — skuplje, oštrije.',

        // Custom models panel
        'models.label':           'Odaberi modele — kreira se jedna persona po modelu.',
        'models.selected':        '{n} odabrano',
        'models.hint':            'Sustav osmisli jednu personu po modelu nakon prve poruke; nikad 2 ista modela u istom panelu.',

        // Custom (persona + model pairs) panel
        'custom.pickModel':       '— Odaberi model —',
        'custom.add':             '+ Dodaj',
        'custom.panelLabel':      'Panel ({n})',
        'custom.emptyHint':       'Još nema parova — odaberi personu i model, pa klikni Dodaj. Možeš dodati istu personu 2× s različitim modelom.',

        // BalanceBanner
        'balance.emptyTitle':     'Wallet je prazan',
        'balance.emptyBody':      'Nadoplati preko PIN-a da pošalješ poruku i pokreneš boardroom.',
        'balance.lowTitle':       'Malo kredita preostalo',
        'balance.lowBody':        'Preostalo ti je {currency} {amount}. Nadoplati prije nego sljedeća rasprava ostane bez kredita.',
        'balance.compactEmpty':   'Nadoplati za nastavak',
        'balance.compactLow':     'Nisko: €{amount}',
        'balance.topup':          'Nadoplati',

        // Show (chat detail) — status, hints, drawer
        'show.statusActive':      'Aktivna',
        'show.statusPaused':      'Pauzirana',
        'show.loadEarlier':       'Učitaj ranije poruke',
        'show.heroTitle':         'Spreman kada budeš ti',
        'show.heroBody':          'Upiši temu dolje. Boardroom će je preuzeti i odgovoriti persona po persona.',
        'show.thinking':          '{name} razmišlja · krug {round}',
        'show.pausedShort':       'Rasprava pauzirana. Klikni {resume} za nastavak.',
        'show.drawerTitle':       'Boardroom',
        'show.headerPersonas':    'Persone',

        // Composer (ChatInputBar)
        'input.enterHint':        'Enter šalje · Shift+Enter za novi red',
        'input.walletEmpty':      'Wallet prazan — nadoplati za slanje',
        'input.topupBtn':         'Nadoplati',

        // First-message bootstrap indicator (between user msg + first persona)
        'show.preparing':         'Pripremam boardroom — biram persone i modele…',
        'show.preparingNext':     'Sljedeća persona se priprema…',

        // Sidebar chat-row actions menu
        'chat.actions.open':      'Otvori menu',
        'chat.actions.archive':   'Arhiviraj',
        'chat.actions.delete':    'Obriši',
        'chat.actions.deleteConfirmTitle': 'Obrisati ovaj chat?',
        'chat.actions.deleteConfirmBody':  'Ova akcija je trajna — sve poruke, sinteze i Chair odluke se brišu zauvijek.',
        'chat.actions.deleteConfirmYes':   'Da, obriši',
        'chat.actions.deleteConfirmNo':    'Odustani',
    },
    en: {
        'app.brand': 'Cortex',
        'app.tagline': 'Multi-model AI brainstorming platform',
        'app.langToggle.title': 'Change interface language',

        'index.heading': '🧠 Cortex Boardroom',
        'index.adminLink': '⚙ Admin',
        'index.newBoardroom': 'New boardroom',
        'index.titlePlaceholder': 'Title or topic of the discussion',
        'index.descriptionPlaceholder': 'Description (optional)',
        'index.contextPlaceholder': 'Known context — data model, current state (optional)',
        'index.constraintsPlaceholder': 'Hard constraints — what proposals must not violate (optional)',
        'index.architectLabel': 'Architect',
        'index.architectDesc': 'A model designs a role panel tailored to the topic (instead of manual selection)',
        'index.strongLabel': 'Strong',
        'index.strongDesc': 'Panel on each provider\'s flagship model (pricier, stronger)',
        'index.architectHint': '🧩 Architect will design the role panel from the title and description — manual persona selection is disabled.',
        'index.personasLabel': 'Personas ({n} selected)',
        'index.submit': 'Start boardroom',
        'index.chatsHeading': 'Your chats',
        'index.noChats': 'No chats yet — start your first boardroom.',
        'index.chatMeta': '€{cost} · {n} msg',

        'show.pause': '⏸ Pause',
        'show.resume': '▶ Resume',
        'show.empty': 'Send the first message — the boardroom starts and runs until you pause it.',
        'show.typing': '{name} is thinking… (round {round})',
        'show.pausedHint': '⏸ Discussion is paused — "Resume" brings it back, or send a new message.',
        'show.sendFailed': 'Failed to send message.',

        'panel.totalCost': 'Total cost',
        'panel.meta': '{messages} messages · {tokens} tokens · round {round}',
        'panel.personasHeading': 'Personas in the boardroom',
        'panel.noModel': 'no model',
        'panel.adminLink': '⚙ Cortex Settings (Admin)',

        'input.placeholder': 'Message the boardroom — Enter sends, Shift+Enter for newline',
        'input.urlPlaceholder': 'https://example.com/article',
        'input.send': 'Send',
        'input.sending': '…',
        'input.attachImage': 'Attach image',
        'input.attachUrl': 'Attach URL',
        'input.powershell': 'PowerShell command',

        'login.title': 'Sign in',
        'login.subtitle': 'Continue to your AI boardroom.',
        'login.email': 'Email',
        'login.password': 'Password',
        'login.remember': 'Remember me',
        'login.forgot': 'Forgot your password?',
        'login.submit': 'Sign in',
        'login.submitting': 'Signing in…',

        'guest.headline': 'A council of AI experts for your hardest decisions',
        'guest.lead': 'A boardroom of AI personas — each on its own model — debates your topic from multiple angles, the way no single model would.',
        'guest.feature1': 'Several AI models debate, not just one',
        'guest.feature2': 'Scribe summarizes, Chair makes the call',
        'guest.feature3': 'Structured synthesis with a priority matrix',
        'guest.footer': 'A personal tool for multi-perspective AI brainstorming',

        // Sidebar (chat list)
        'sidebar.newChat':  'New boardroom',
        'sidebar.empty':    'No discussions yet',
        'sidebar.today':    'Today',
        'sidebar.yesterday': 'Yesterday',
        'sidebar.earlier':  'Earlier',
        'sidebar.admin':    'Admin',
        'sidebar.expand':   'Expand',
        'sidebar.collapse': 'Collapse',

        // Welcome screen
        'welcome.greeting':       'Welcome back, {name}.',
        'welcome.greetingAnon':   'Start a discussion',
        'welcome.lead':           'Multi-model AI panel — every persona on its own model, one synthesis at the end.',
        'welcome.titlePh':        'Title (optional)',
        'welcome.topicPh':        'What should the boardroom discuss? (you can also write this as the first message after starting)',
        'welcome.startBtn':       'Start discussion',
        'welcome.balance':        'Balance: €{balance}',
        'welcome.topupBtn':       'Top up to start',
        'welcome.recent':         'Recent',

        // Init mode tabs
        'mode.quick.label':       'Quick start',
        'mode.quick.hint':        'Just pick a head-count',
        'mode.custommodels.label': 'Custom models',
        'mode.custommodels.hint':  'Pick exact AI models',
        'mode.custom.label':      'Custom',
        'mode.custom.hint':       'Pick persona + model',

        // Quick panel
        'quick.label':            'How many AI agents in the panel?',
        'quick.hint':             'Models and personas are chosen after your first message — system reads its complexity and picks fitting roles + distinct AI models.',
        'quick.strongLabel':      'Strong tier',
        'quick.strongHint':       'Force flagship models (Opus 4.7, o3, Grok 3, Gemini Pro) — pricier, sharper.',

        // Custom models panel
        'models.label':           'Pick the models you want — one persona will be generated per model.',
        'models.selected':        '{n} selected',
        'models.hint':            'System invents one persona per model after your first message; no two same models in one panel.',

        // Custom pairs panel
        'custom.pickModel':       '— Pick a model —',
        'custom.add':             '+ Add',
        'custom.panelLabel':      'Panel ({n})',
        'custom.emptyHint':       'No pairs yet — pick a persona and a model, then hit Add. You can add the same persona twice with a different model.',

        // BalanceBanner
        'balance.emptyTitle':     'Your wallet is empty',
        'balance.emptyBody':      'Redeem a top-up PIN to send messages and run the boardroom.',
        'balance.lowTitle':       'Running low on credits',
        'balance.lowBody':        'You have {currency} {amount} left. Top up before the next discussion runs out.',
        'balance.compactEmpty':   'Top up to continue',
        'balance.compactLow':     'Low: €{amount}',
        'balance.topup':          'Top up',

        // Show (chat detail)
        'show.statusActive':      'Active',
        'show.statusPaused':      'Paused',
        'show.loadEarlier':       'Load earlier messages',
        'show.heroTitle':         'Ready when you are',
        'show.heroBody':          'Type a topic below. The boardroom will pick it up and respond persona-by-persona.',
        'show.thinking':          '{name} is thinking · round {round}',
        'show.pausedShort':       'Discussion paused. Click {resume} to continue.',
        'show.drawerTitle':       'Boardroom',
        'show.headerPersonas':    'Personas',

        // Composer
        'input.enterHint':        'Enter to send · Shift+Enter for a new line',
        'input.walletEmpty':      'Wallet empty — top up to send messages',
        'input.topupBtn':         'Top up',

        // First-message bootstrap indicator
        'show.preparing':         'Preparing the boardroom — picking personas and models…',
        'show.preparingNext':     'Next persona is warming up…',

        // Sidebar chat-row actions menu
        'chat.actions.open':      'Open menu',
        'chat.actions.archive':   'Archive',
        'chat.actions.delete':    'Delete',
        'chat.actions.deleteConfirmTitle': 'Delete this chat?',
        'chat.actions.deleteConfirmBody':  'This is permanent — all messages, syntheses and Chair verdicts are wiped for good.',
        'chat.actions.deleteConfirmYes':   'Yes, delete',
        'chat.actions.deleteConfirmNo':    'Cancel',
    },
};
