---
title: Βιβλιοθήκη blocks
status: published
visible: false
translation_id: 7b1c2d3e4f5a6b7c
excerpt: 'Όλα τα blocks του θέματος και οι παραλλαγές τους σε μία σελίδα. Ορατή μόνο σε συνδεδεμένους διαχειριστές.'
seo:
  noindex: true
blocks:
  - type: hero
    variant: minimal
    eyebrow: Βιβλιοθήκη blocks
    heading: Όλες οι ενότητες του θέματος σε μία σελίδα.
    text: Χρησιμοποιήστε τη σελίδα αυτή για να δείτε κάθε block, κάθε παραλλαγή και κάθε φόντο πριν τα βάλετε σε πραγματικές σελίδες.
    actions:
      - label: Ενότητες κειμένου
        url: '#text'
      - label: Φόρμα
        url: '#form'
        style: secondary
  - type: text
    variant: lead
    anchor: text
    eyebrow: 'Text · lead'
    heading: Εισαγωγή με μεγάλα γράμματα
    body: Ιδανικό για την πρώτη παράγραφο μιας σελίδας, όπου θέλετε να πείτε **το βασικό μήνυμα** καθαρά και με άνεση.
  - type: text
    variant: split
    tone: muted
    eyebrow: 'Text · split'
    heading: Τίτλος δίπλα στο κείμενο
    body: |
      Η παραλλαγή αυτή κρατά τον τίτλο σταθερό αριστερά όσο διαβάζετε το κείμενο δεξιά, κάτι που βοηθά σε μεγάλα κείμενα.

      ### Υπότιτλος

      Υποστηρίζει κανονικά Markdown: **έντονα**, *πλάγια*, [συνδέσμους](/about), λίστες και παραθέματα.

      > Ένα παράθεμα ξεχωρίζει με τη γραμμή στο χρώμα της παλέτας.
    actions:
      - label: Σχετικά με εμάς
        url: about
        style: secondary
  - type: text
    align: center
    eyebrow: 'Text · default'
    heading: Κεντραρισμένο κείμενο
    body: Για σύντομες δηλώσεις ή μεταβάσεις ανάμεσα σε ενότητες.
  - type: text-image
    variant: image-left
    eyebrow: 'Text & image · image left'
    heading: Εικόνα αριστερά, πορτρέτο
    body: |
      Οι λίστες εμφανίζονται με σημάδια ελέγχου:

      - Responsive εικόνες σε WebP
      - Σωστές διαστάσεις για να μη «χοροπηδά» η σελίδα
      - Lazy loading εκτός της πρώτης οθόνης
    image: /uploads/media/5e6915a67b9ceec5.jpg
    image_alt: Αεροφωτογραφία λίμνης ανάμεσα σε βράχια
    image_ratio: portrait
  - type: features
    variant: plain
    tone: muted
    eyebrow: 'Features · plain · 4 στήλες'
    heading: Χωρίς κάρτες
    columns: '4'
    items:
      - { icon: shield, title: Ασφάλεια, text: 'Ενημερώσεις, αντίγραφα ασφαλείας και προστασία φορμών.' }
      - { icon: bolt, title: Ταχύτητα, text: 'Ελάχιστο JavaScript και εικόνες στο σωστό μέγεθος.' }
      - { icon: globe, title: Πολυγλωσσία, text: 'Κάθε σελίδα σε όσες γλώσσες χρειάζεστε.' }
      - { icon: users, title: Προσβασιμότητα, text: 'Σημασιολογικό HTML και πλήρης χρήση με πληκτρολόγιο.' }
  - type: features
    variant: numbered
    eyebrow: 'Features · numbered'
    heading: Η διαδικασία μας
    align: center
    items:
      - { title: Γνωριμία, text: 'Καταλαβαίνουμε στόχους, κοινό και περιεχόμενο.' }
      - { title: Σχεδιασμός, text: 'Προτείνουμε δομή και εμφάνιση και τα συζητάμε μαζί.' }
      - { title: Υλοποίηση, text: 'Χτίζουμε, δοκιμάζουμε και ανεβάζουμε το site.' }
  - type: stats
    variant: cards
    tone: contrast
    eyebrow: 'Stats · cards · contrast'
    heading: Αριθμοί σε κάρτες
    items:
      - { value: '6–10', label: Εβδομάδες, text: 'Μέση διάρκεια έργου' }
      - { value: '< 1s', label: Φόρτωση, text: 'Σε σύγχρονη σύνδεση' }
      - { value: '100%', label: Πληκτρολόγιο, text: 'Πλοήγηση χωρίς ποντίκι' }
  - type: testimonials
    variant: featured
    eyebrow: 'Testimonials · featured'
    items:
      - quote: Η καλύτερη απόφαση που πήραμε φέτος ήταν να ξαναφτιάξουμε το site μας από την αρχή.
        name: Γιώργος Παπαδόπουλος
        role: Γενικός Διευθυντής, Gamma Hotels
  - type: logos
    variant: grid
    tone: muted
    heading: 'Logos · grid'
    items:
      - { name: Northbridge }
      - { name: Alpha Estates }
      - { name: Beta Retail }
      - { name: Gamma Hotels }
      - { name: Aegean Labs }
      - { name: Delta Legal }
  - type: cards
    variant: list
    source: posts
    limit: 2
    eyebrow: 'Cards · list · posts'
    heading: Από το blog
    link_label: Όλα τα άρθρα
    link_url: posts
  - type: cards
    columns: '2'
    tone: muted
    eyebrow: 'Cards · manual'
    heading: Κάρτες με το χέρι
    items:
      - { title: Οδηγός επωνυμίας, text: 'Ένα PDF με χρώματα, γραμματοσειρές και παραδείγματα.', meta: Οδηγός, image: /uploads/media/faros-demo-editor.svg, url: about }
      - { title: Μελέτη περίπτωσης, text: 'Πώς ένα ξενοδοχείο διπλασίασε τις απευθείας κρατήσεις.', meta: Case study, image: /uploads/media/5e6915a67b9ceec5.jpg, url: projects }
  - type: faq
    variant: stacked
    eyebrow: 'FAQ · stacked'
    heading: Ερωτήσεις σε μία στήλη
    open_first: true
    items:
      - question: Πώς προσθέτω ένα block;
        answer: 'Προς το παρόν από το πεδίο **Front Matter** στη σελίδα επεξεργασίας. Ο οπτικός επεξεργαστής blocks έρχεται στην επόμενη φάση.'
      - question: Μπορώ να κρύψω ένα block προσωρινά;
        answer: 'Ναι, με `hidden: true`. Το block μένει αποθηκευμένο αλλά δεν εμφανίζεται.'
  - type: accordion
    eyebrow: 'Accordion · compact'
    heading: Συμπαγή ακορντεόν δίπλα δίπλα
    width: 50
    initial: first
    items:
      - title: Πρώτο θέμα
        content: "Σύντομο κείμενο με λίστα:\n\n- πρώτο\n- δεύτερο"
      - title: Δεύτερο θέμα
        content: Σύντομη εξήγηση με [σύνδεσμο](contact).
  - type: accordion
    heading: Ο ένας ανοιχτός κάθε φορά
    width: 50
    multiple: false
    icon: plus
    style: cards
    items:
      - title: Πρώτο θέμα
        content: Σύντομη εξήγηση.
      - title: Δεύτερο θέμα
        content: Σύντομη εξήγηση.
  - type: cta
    variant: band
    eyebrow: 'CTA · band · accent'
    heading: Ένα έντονο κάλεσμα σε όλο το πλάτος
    text: Το φόντο παίρνει το χρώμα της παλέτας από τις ρυθμίσεις του θέματος.
    actions:
      - { label: Επικοινωνία, url: contact }
      - { label: Υπηρεσίες, url: services, style: secondary }
  - type: cta
    variant: split
    tone: muted
    heading: 'CTA · split: κείμενο αριστερά, κουμπιά δεξιά'
    actions:
      - { label: Ξεκινήστε, url: contact }
  - type: gallery
    variant: grid
    eyebrow: 'Gallery · grid · lightbox'
    heading: Εικόνες σε πλέγμα
    columns: '4'
    ratio: square
    images:
      - { image: /uploads/media/faros-demo-lake.jpg, caption: Η λίμνη }
      - { image: /uploads/media/faros-demo-rocks.jpg, caption: Οι βράχοι }
      - { image: /uploads/media/faros-demo-trees.jpg, caption: Τα δέντρα }
      - { image: /uploads/media/faros-demo-path.jpg, caption: Το μονοπάτι }
  - type: gallery
    variant: masonry
    tone: muted
    eyebrow: 'Gallery · masonry'
    heading: Κάθε εικόνα στο δικό της σχήμα
    columns: '3'
    images:
      - { image: /uploads/media/faros-demo-rocks.jpg }
      - { image: /uploads/media/faros-demo-trees.jpg }
      - { image: /uploads/media/faros-demo-lake.jpg }
      - { image: /uploads/media/faros-demo-path.jpg }
      - { image: /uploads/media/5e6915a67b9ceec5.jpg }
  - type: gallery
    variant: strip
    eyebrow: 'Gallery · strip'
    heading: Κύλιση οριζόντια
    images:
      - { image: /uploads/media/faros-demo-lake.jpg }
      - { image: /uploads/media/faros-demo-trees.jpg }
      - { image: /uploads/media/faros-demo-path.jpg }
      - { image: /uploads/media/5e6915a67b9ceec5.jpg }
  - type: team
    variant: compact
    columns: '3'
    tone: muted
    eyebrow: 'Team · compact'
    heading: Συμπαγής λίστα
    members:
      - { name: Άννα Ραφτοπούλου, role: Στρατηγική, email: anna@gnoziocms.test }
      - { name: Δημήτρης Λάσκαρης, role: Design, linkedin: 'https://www.linkedin.com/' }
      - { name: Κατερίνα Βλάχου, role: Ανάπτυξη }
  - type: timeline
    variant: vertical
    eyebrow: 'Timeline · vertical'
    heading: Κάθετο χρονολόγιο
    items:
      - { label: Εβδομάδα 1, title: Γνωριμία, text: Στόχοι και περιεχόμενο. }
      - { label: Εβδομάδα 3, title: Σχεδιασμός, text: Δομή και εμφάνιση. }
      - { label: Εβδομάδα 8, title: Παράδοση, text: Το site ανεβαίνει. }
  - type: timeline
    variant: steps
    tone: muted
    eyebrow: 'Timeline · steps'
    heading: Βήματα σε σειρά
    items:
      - { label: '01', title: Brief, text: Τι χρειάζεστε. }
      - { label: '02', title: Πρόταση, text: Χρόνος και κόστος. }
      - { label: '03', title: Υλοποίηση, text: Σχεδιασμός και ανάπτυξη. }
      - { label: '04', title: Υποστήριξη, text: Βελτιώσεις κάθε μήνα. }
  - type: contact
    variant: cards
    eyebrow: 'Contact · cards'
    heading: Στοιχεία σε κάρτες
    items:
      - { icon: mail, label: Email, value: hello@gnoziocms.test, url: 'mailto:hello@gnoziocms.test' }
      - { icon: phone, label: Τηλέφωνο, value: '+30 210 000 0000', url: 'tel:+302100000000' }
      - { icon: map-pin, label: Γραφείο, value: Αθήνα }
  - type: map
    variant: split
    tone: muted
    eyebrow: 'Map · split'
    heading: Πού θα μας βρείτε
    intro: Ο χάρτης φορτώνει μόνο όταν το ζητήσει ο επισκέπτης.
    lat: 37.9755
    lng: 23.7348
    zoom: 15
    place_name: το γραφείο μας
    address: "Πλατεία Συντάγματος\n105 57 Αθήνα"
  - type: form
    variant: split
    anchor: form
    eyebrow: 'Form · split'
    heading: Επικοινωνήστε μαζί μας
    intro: Συμπληρώστε τη φόρμα και θα σας απαντήσουμε μέσα σε μία εργάσιμη.
    form: contact
  - type: banner
    variant: strip
    title: Νέο
    text: Ανοίγουμε νέες θέσεις συνεργασίας για το φθινόπωρο.
    link_label: Δείτε τις θέσεις
    url: careers
    dismissible: true
  - type: banner
    variant: callout
    tone: muted
    title: Δωρεάν αρχική συμβουλευτική
    text: Κλείστε μια συνάντηση 30 λεπτών και δείτε πώς μπορούμε να βοηθήσουμε.
    link_label: Κλείστε ραντεβού
    url: contact
    icon: sparkles
  - type: latest
    variant: cards
    anchor: latest
    eyebrow: 'Latest · cards'
    heading: Τελευταία άρθρα
    intro: Ενημερώνεται αυτόματα όταν δημοσιεύετε νέο περιεχόμενο.
    link_label: Όλα τα άρθρα
    link_url: posts
  - type: latest
    variant: list
    tone: muted
    eyebrow: 'Latest · minimal list'
    heading: Ήσυχη λίστα
    limit: 4
  - type: latest
    variant: compact
    eyebrow: 'Latest · compact'
    heading: Συμπαγής λίστα με μικρογραφίες
    source: projects
  - type: latest
    variant: overlay
    tone: muted
    eyebrow: 'Latest · overlay'
    heading: Έργα ως εικόνες με τίτλο
    source: projects
  - type: latest
    variant: strip
    eyebrow: 'Latest · strip'
    heading: Οριζόντια λωρίδα
    source: projects
    limit: 6
  - type: latest
    variant: featured
    tone: muted
    eyebrow: 'Latest · featured'
    heading: Κύριο θέμα και λίστα
    limit: 4
  - type: latest
    variant: magazine
    eyebrow: 'Latest · magazine'
    heading: Στυλ περιοδικού
    source: projects
    limit: 4
  - type: latest
    variant: editorial
    tone: muted
    eyebrow: 'Latest · editorial'
    heading: Εκδοτική παρουσίαση
    limit: 3
  - type: video
    variant: featured
    anchor: video
    eyebrow: 'Video · featured'
    heading: Δείτε πώς δουλεύουμε
    intro: Το βίντεο ανοίγει σε μεγάλο παράθυρο πάνω από τη σελίδα. Τίποτα δεν φορτώνεται από το YouTube μέχρι να πατήσετε αναπαραγωγή.
    videos:
      - url: 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'
        title: Παρουσίαση της ομάδας
        caption: Ένα πλάνο από την καθημερινότητα του γραφείου.
        poster: /uploads/media/faros-demo-lake.jpg
        duration: '10:34'
  - type: video
    variant: grid
    tone: muted
    eyebrow: 'Video · grid'
    heading: Σειρά βίντεο
    videos:
      - url: 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'
        title: Πρώτο επεισόδιο
        caption: Η ιδέα και οι στόχοι.
        poster: /uploads/media/faros-demo-path.jpg
        duration: '10:34'
      - url: 'https://vimeo.com/76979871'
        title: Δεύτερο επεισόδιο
        caption: Από το σχέδιο στην υλοποίηση.
        poster: /uploads/media/faros-demo-rocks.jpg
        duration: '2:10'
      - url: 'https://youtu.be/aqz-KE-bpKQ?t=30'
        title: Τρίτο επεισόδιο
        caption: Χωρίς εικόνα προεπισκόπησης.
  - type: video
    variant: split
    eyebrow: 'Video · split'
    heading: Ιστορία ενός έργου
    intro: Κείμενο δίπλα στο βίντεο, για πιο εξηγητικές ενότητες.
    ratio: classic
    videos:
      - url: 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'
        title: Case study
        poster: /uploads/media/faros-demo-trees.jpg
  - type: hero-slider
    anchor: slider
    items:
      - layout: cover
        image: /uploads/media/faros-demo-lake.jpg
        eyebrow: Ξενοδοχεία
        heading: Ανακαίνιση με θέα
        text: Ένας χώρος που ανοίγει προς το τοπίο.
        label: Δείτε το έργο
        url: projects
      - layout: split
        image: /uploads/media/faros-demo-path.jpg
        eyebrow: Γραφεία
        heading: Χώροι που εμπνέουν
        text: Φως, υλικά και ροή για την καθημερινότητα της ομάδας.
        label: Δείτε το έργο
        url: projects
        label_2: Επικοινωνία
        url_2: contact
        highlight_1: Μελέτη
        highlight_2: Υλοποίηση
      - layout: cover-centered
        image: /uploads/media/faros-demo-trees.jpg
        eyebrow: Λιανική
        heading: Εμπειρία καταστήματος
        text: Από την πρώτη ματιά μέχρι το ταμείο.
        label: Δείτε το έργο
        url: projects
      - layout: minimal
        eyebrow: Χωρίς εικόνα
        heading: Και ένα slide μόνο με κείμενο
        text: Ένα slide δεν χρειάζεται πάντα φωτογραφία.
  - type: pricing
    variant: cards
    anchor: pricing
    eyebrow: 'Pricing · cards'
    heading: Πακέτα συνεργασίας
    intro: Ξεκάθαρες τιμές, χωρίς εκπλήξεις.
    note: Οι τιμές δεν περιλαμβάνουν ΦΠΑ.
    items:
      - name: Βασικό
        price: '€490'
        period: εφάπαξ
        description: Για μικρές επιχειρήσεις που ξεκινούν.
        features: "Έως 5 σελίδες\nΒασικό SEO\nΦόρμα επικοινωνίας"
        button_label: Ζητήστε προσφορά
        button_url: contact
      - name: Επαγγελματικό
        badge: Δημοφιλές
        price: '€1.290'
        period: εφάπαξ
        description: Για εταιρείες που θέλουν πλήρη παρουσία.
        features: "Έως 15 σελίδες\nΆρθρα και έργα\nΠολύγλωσσο\nΕκπαίδευση ομάδας"
        button_label: Ζητήστε προσφορά
        button_url: contact
        highlight: true
      - name: Συνδρομή φροντίδας
        price: '€60'
        period: ανά μήνα
        description: Ενημερώσεις, backups και υποστήριξη.
        features: "Μηνιαίες ενημερώσεις\nΑντίγραφα ασφαλείας\nΥποστήριξη με email"
        button_label: Μάθετε περισσότερα
        button_url: contact
  - type: pricing
    variant: list
    tone: muted
    eyebrow: 'Pricing · list'
    heading: Τιμοκατάλογος υπηρεσιών
    items:
      - { name: Συμβουλευτική συνάντηση, description: Μία ώρα με έναν σύμβουλο., price: '€80', period: ανά ώρα, button_label: Κράτηση, button_url: contact }
      - { name: Ανάλυση χώρου, description: Καταγραφή και πρόταση βελτίωσης., price: '€450', period: ανά χώρο, button_label: Κράτηση, button_url: contact, highlight: true }
      - { name: Πλήρης μελέτη, description: 'Σχέδια, υλικά και προϋπολογισμός.', price: 'Κατόπιν', period: συνεννόησης, button_label: Επικοινωνία, button_url: contact }
  - type: tabs
    variant: horizontal
    anchor: tabs
    eyebrow: 'Tabs · horizontal'
    heading: Οι υπηρεσίες μας
    items:
      - label: Στρατηγική
        title: Στρατηγική χώρου
        text: "Καταγράφουμε πώς χρησιμοποιείται ο χώρος σήμερα και **πώς θα έπρεπε**.\n\n- Έρευνα χρήσης\n- Στόχοι και προτεραιότητες"
        image: /uploads/media/faros-demo-lake.jpg
        link_label: Μάθετε περισσότερα
        url: workplace-strategy
      - label: Σχεδιασμός
        title: Σχεδιασμός και μελέτη
        text: Από την πρώτη ιδέα μέχρι τα τελικά σχέδια εφαρμογής.
        image: /uploads/media/faros-demo-path.jpg
      - label: Υλοποίηση
        title: Υλοποίηση και παράδοση
        text: Συντονίζουμε τεχνίτες και προμηθευτές, με σαφές πρόγραμμα.
  - type: tabs
    variant: vertical
    tone: muted
    eyebrow: 'Tabs · vertical'
    heading: Συχνά θέματα
    items:
      - { label: Χρόνοι, title: Πόσο διαρκεί ένα έργο, text: 'Από 6 έως 16 εβδομάδες, ανάλογα με το μέγεθος.' }
      - { label: Κόστος, title: Πώς διαμορφώνεται η τιμή, text: 'Ξεκινά από την ανάλυση του χώρου και το εύρος του έργου.' }
      - { label: Υποστήριξη, title: Τι γίνεται μετά την παράδοση, text: 'Παραμένουμε δίπλα σας με υποστήριξη και συντήρηση.' }
  - type: hero
    variant: steps
    anchor: hero-steps
    eyebrow: 'Hero · steps'
    heading: Από την ιδέα στην παράδοση
    text: Τέσσερα βήματα, ένας υπεύθυνος έργου και καθαρές προθεσμίες σε κάθε φάση.
    image: /uploads/media/faros-demo-trees.jpg
    image_alt: Αεροφωτογραφία δέντρων το φθινόπωρο
    actions:
      - { label: Ξεκινήστε ένα έργο, url: contact }
    items:
      - { title: Ανάλυση, text: Καταγράφουμε τον χώρο και τις ανάγκες σας., link: services, link_label: Οι υπηρεσίες μας }
      - { title: Πρόταση, text: 'Σχέδια, υλικά και προϋπολογισμός σε ένα έγγραφο.' }
      - { title: Υλοποίηση, text: Κατασκευή με σταθερό χρονοδιάγραμμα. }
      - { title: Παράδοση, text: Τελικός έλεγχος και υποστήριξη μετά την παράδοση., link: contact }
  - type: compare
    variant: lines
    anchor: compare
    eyebrow: 'Compare · lines'
    heading: Τι περιλαμβάνει κάθε πακέτο
    intro: Ναι και όχι γίνονται τικ και σταυρός. Οτιδήποτε άλλο εμφανίζεται ως κείμενο.
    columns:
      - { name: Βασικό }
      - { name: Επαγγελματικό, badge: Προτεινόμενο, highlight: true, button_label: Επικοινωνία, button_url: contact }
      - { name: Εταιρικό }
    rows:
      - { label: Σχεδιασμός, group: true }
      - { label: Αρχική μελέτη, v1: ναι, v2: ναι, v3: ναι }
      - { label: Τρισδιάστατες απεικονίσεις, v1: όχι, v2: ναι, v3: ναι }
      - { label: Υποστήριξη, group: true }
      - { label: Χρόνος απάντησης, v1: 3 ημέρες, v2: 1 ημέρα, v3: 4 ώρες }
      - { label: Προσωπικός σύμβουλος, v1: όχι, v2: όχι, v3: ναι }
    note: Τα χαρακτηριστικά είναι ενδεικτικά.
  - type: compare
    variant: striped
    tone: muted
    eyebrow: 'Compare · striped'
    heading: Δύο προσεγγίσεις
    columns:
      - { name: Ανακαίνιση }
      - { name: Νέα κατασκευή }
    rows:
      - { label: Διάρκεια, v1: 6–10 εβδομάδες, v2: 4–8 μήνες }
      - { label: Άδεια, v1: Συχνά όχι, v2: Ναι }
      - { label: Ευελιξία στο σχέδιο, v1: Περιορισμένη, v2: Πλήρης }
  - type: before-after
    variant: slider
    anchor: before-after
    eyebrow: 'Before and after · slider'
    heading: Πριν και μετά
    intro: Σύρετε τη λαβή ή χρησιμοποιήστε τα βελάκια του πληκτρολογίου.
    before: /uploads/media/faros-demo-rocks.jpg
    before_alt: Ο χώρος πριν την παρέμβαση
    after: /uploads/media/faros-demo-lake.jpg
    after_alt: Ο ίδιος χώρος μετά την παρέμβαση
    caption: Ενδεικτικές εικόνες.
  - type: before-after
    variant: side
    tone: muted
    eyebrow: 'Before and after · side by side'
    before: /uploads/media/faros-demo-path.jpg
    before_label: Παλιά όψη
    after: /uploads/media/faros-demo-trees.jpg
    after_label: Νέα όψη
    image_ratio: wide
  - type: content
---
## Block «Page content»

Το κείμενο Markdown της σελίδας εμφανίζεται εκεί που μπαίνει το block `content`. Αν μια σελίδα με blocks δεν έχει τέτοιο block, το κείμενο εμφανίζεται αμέσως μετά το αρχικό hero.
