<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The ready-made forms the "New form" screen offers: a contact form, a newsletter sign-up, a quote request, and so on.
 * Each one is only a starting point (the fields, the button, the message after sending, and the emails) that the form
 * builder opens for editing before anything is saved. Texts come in English and Greek; any other language gets English.
 */
final class FormTemplates
{
    /** @return string[] the ids of the templates, in the order they are offered */
    public static function ids(): array
    {
        return ['blank', 'contact', 'newsletter', 'quote', 'booking', 'event', 'support', 'feedback'];
    }

    /**
     * @return array<string, array{id: string, name: string, description: string, icon: string, title: string, submit_label: string, success_message: string, fields: array<int, array<string, mixed>>, notifications: array<string, mixed>}>
     */
    public static function all(string $lang): array
    {
        $templates = [];
        foreach (self::ids() as $id) {
            $templates[$id] = self::get($id, $lang) ?? [];
        }
        return array_filter($templates);
    }

    /** @return array{id: string, name: string, description: string, icon: string, title: string, submit_label: string, success_message: string, fields: array<int, array<string, mixed>>, notifications: array<string, mixed>}|null */
    public static function get(string $id, string $lang): ?array
    {
        $t = static fn(string $en, string $el): string => $lang === 'el' ? $el : $en;
        $field = static fn(string $type, string $name, string $label, array $more = []): array => ['type' => $type, 'name' => $name, 'label' => $label] + $more;
        $consent = $field('checkbox', 'consent', $t('I agree to the processing of my information so you can reply to me.', 'Συμφωνώ με την επεξεργασία των στοιχείων μου ώστε να λάβω απάντηση.'), ['required' => true]);
        $reply = static fn(string $subject, string $message): array => [
            'enabled' => true, 'to' => '', 'reply_to_field' => 'email', 'auto_reply' => true, 'auto_reply_include' => false,
            'auto_reply_subject' => $subject, 'auto_reply_message' => $message,
        ];

        switch ($id) {
            case 'blank':
                return [
                    'id' => 'blank', 'icon' => 'blank', 'name' => $t('Blank form', 'Κενή φόρμα'),
                    'description' => $t('Start with nothing and add the fields you need.', 'Ξεκίνα από το μηδέν και πρόσθεσε τα πεδία που χρειάζεσαι.'),
                    'title' => $t('New form', 'Νέα φόρμα'), 'submit_label' => $t('Send', 'Αποστολή'),
                    'success_message' => $t('Thank you. Your message has been sent.', 'Ευχαριστούμε. Το μήνυμά σου στάλθηκε.'),
                    'fields' => [], 'notifications' => ['enabled' => true, 'to' => '', 'reply_to_field' => '', 'auto_reply' => false],
                ];
            case 'contact':
                return [
                    'id' => 'contact', 'icon' => 'mail', 'name' => $t('Contact', 'Επικοινωνία'),
                    'description' => $t('Name, email, phone and a message, with a consent box.', 'Όνομα, email, τηλέφωνο και μήνυμα, με πεδίο συναίνεσης.'),
                    'title' => $t('Contact form', 'Φόρμα επικοινωνίας'), 'submit_label' => $t('Send message', 'Αποστολή μηνύματος'),
                    'success_message' => $t('Thank you. Your message has been sent and we will reply shortly.', 'Ευχαριστούμε. Το μήνυμά σου στάλθηκε και θα απαντήσουμε σύντομα.'),
                    'fields' => [
                        $field('text', 'full-name', $t('Full name', 'Ονοματεπώνυμο'), ['required' => true, 'width' => 'half']),
                        $field('email', 'email', 'Email', ['required' => true, 'width' => 'half']),
                        $field('tel', 'phone', $t('Phone', 'Τηλέφωνο'), ['width' => 'half']),
                        $field('text', 'subject', $t('Subject', 'Θέμα'), ['width' => 'half']),
                        $field('textarea', 'message', $t('Message', 'Μήνυμα'), ['required' => true, 'rows' => 6, 'placeholder' => $t('How can we help?', 'Πώς μπορούμε να βοηθήσουμε;')]),
                        $consent,
                    ],
                    'notifications' => $reply($t('We received your message', 'Λάβαμε το μήνυμά σου'), $t("Hello {full-name},\n\nThank you for contacting us. We received your message and will get back to you soon.", "Γεια σου {full-name},\n\nΕυχαριστούμε που επικοινώνησες μαζί μας. Λάβαμε το μήνυμά σου και θα απαντήσουμε σύντομα.")),
                ];
            case 'newsletter':
                return [
                    'id' => 'newsletter', 'icon' => 'bell', 'name' => $t('Newsletter sign-up', 'Εγγραφή στο newsletter'),
                    'description' => $t('Just an email address and consent.', 'Μόνο μια διεύθυνση email και συναίνεση.'),
                    'title' => $t('Newsletter', 'Newsletter'), 'submit_label' => $t('Subscribe', 'Εγγραφή'),
                    'success_message' => $t('Thank you for subscribing.', 'Ευχαριστούμε για την εγγραφή σου.'),
                    'fields' => [
                        $field('text', 'first-name', $t('First name', 'Όνομα'), ['width' => 'half']),
                        $field('email', 'email', 'Email', ['required' => true, 'width' => 'half']),
                        $field('checkbox', 'consent', $t('I would like to receive news by email.', 'Θέλω να λαμβάνω νέα με email.'), ['required' => true]),
                    ],
                    'notifications' => $reply($t('You are subscribed', 'Η εγγραφή σου ολοκληρώθηκε'), $t("Hello {first-name},\n\nThank you for subscribing.", "Γεια σου {first-name},\n\nΕυχαριστούμε για την εγγραφή σου.")),
                ];
            case 'quote':
                return [
                    'id' => 'quote', 'icon' => 'tag', 'name' => $t('Request a quote', 'Αίτημα προσφοράς'),
                    'description' => $t('Contact details, the service, a budget and the details of the project.', 'Στοιχεία επικοινωνίας, υπηρεσία, προϋπολογισμός και λεπτομέρειες του έργου.'),
                    'title' => $t('Request a quote', 'Αίτημα προσφοράς'), 'submit_label' => $t('Request a quote', 'Αίτημα προσφοράς'),
                    'success_message' => $t('Thank you. We will send you a quote within two working days.', 'Ευχαριστούμε. Θα σου στείλουμε προσφορά μέσα σε δύο εργάσιμες.'),
                    'fields' => [
                        $field('heading', 'heading-1', $t('About you', 'Για σένα')),
                        $field('text', 'full-name', $t('Full name', 'Ονοματεπώνυμο'), ['required' => true, 'width' => 'half']),
                        $field('email', 'email', 'Email', ['required' => true, 'width' => 'half']),
                        $field('text', 'company', $t('Company', 'Εταιρεία'), ['width' => 'half']),
                        $field('tel', 'phone', $t('Phone', 'Τηλέφωνο'), ['width' => 'half']),
                        $field('heading', 'heading-2', $t('The project', 'Το έργο')),
                        $field('select', 'service', $t('What do you need?', 'Τι χρειάζεσαι;'), ['required' => true, 'width' => 'half', 'options' => [$t('Design', 'Σχεδιασμός'), $t('Development', 'Ανάπτυξη'), $t('Consulting', 'Συμβουλευτική'), $t('Something else', 'Κάτι άλλο')]]),
                        $field('select', 'budget', $t('Budget', 'Προϋπολογισμός'), ['width' => 'half', 'options' => [$t('Under 2,000', 'Έως 2.000'), $t('2,000 – 5,000', '2.000 – 5.000'), $t('5,000 – 10,000', '5.000 – 10.000'), $t('Over 10,000', 'Πάνω από 10.000')]]),
                        $field('date', 'deadline', $t('When do you need it?', 'Πότε το χρειάζεσαι;'), ['width' => 'half']),
                        $field('textarea', 'details', $t('Tell us about the project', 'Περίγραψε το έργο'), ['required' => true, 'rows' => 6]),
                        $consent,
                    ],
                    'notifications' => $reply($t('We received your request', 'Λάβαμε το αίτημά σου'), $t("Hello {full-name},\n\nThank you for your request. We will send you a quote soon.", "Γεια σου {full-name},\n\nΕυχαριστούμε για το αίτημά σου. Θα σου στείλουμε προσφορά σύντομα.")),
                ];
            case 'booking':
                return [
                    'id' => 'booking', 'icon' => 'calendar', 'name' => $t('Appointment', 'Ραντεβού'),
                    'description' => $t('A date and time, with contact details and notes.', 'Ημερομηνία και ώρα, με στοιχεία επικοινωνίας και σημειώσεις.'),
                    'title' => $t('Book an appointment', 'Κλείσε ραντεβού'), 'submit_label' => $t('Book', 'Κράτηση'),
                    'success_message' => $t('Thank you. We will confirm your appointment by email.', 'Ευχαριστούμε. Θα επιβεβαιώσουμε το ραντεβού σου με email.'),
                    'fields' => [
                        $field('text', 'full-name', $t('Full name', 'Ονοματεπώνυμο'), ['required' => true, 'width' => 'half']),
                        $field('email', 'email', 'Email', ['required' => true, 'width' => 'half']),
                        $field('tel', 'phone', $t('Phone', 'Τηλέφωνο'), ['width' => 'half']),
                        $field('select', 'service', $t('Service', 'Υπηρεσία'), ['width' => 'half', 'options' => [$t('First visit', 'Πρώτη επίσκεψη'), $t('Follow-up', 'Επανεξέταση'), $t('Other', 'Άλλο')]]),
                        $field('date', 'date', $t('Preferred date', 'Προτιμώμενη ημερομηνία'), ['required' => true, 'width' => 'half']),
                        $field('time', 'time', $t('Preferred time', 'Προτιμώμενη ώρα'), ['required' => true, 'width' => 'half']),
                        $field('textarea', 'notes', $t('Notes', 'Σημειώσεις'), ['rows' => 4]),
                        $consent,
                    ],
                    'notifications' => $reply($t('We received your booking request', 'Λάβαμε το αίτημα κράτησής σου'), $t("Hello {full-name},\n\nWe received your request for {date} at {time} and will confirm it shortly.", "Γεια σου {full-name},\n\nΛάβαμε το αίτημά σου για {date} στις {time} και θα το επιβεβαιώσουμε σύντομα.")),
                ];
            case 'event':
                return [
                    'id' => 'event', 'icon' => 'star', 'name' => $t('Event registration', 'Εγγραφή σε εκδήλωση'),
                    'description' => $t('Name, email, number of guests and what they need.', 'Όνομα, email, αριθμός καλεσμένων και ανάγκες τους.'),
                    'title' => $t('Event registration', 'Εγγραφή σε εκδήλωση'), 'submit_label' => $t('Register', 'Εγγραφή'),
                    'success_message' => $t('You are registered. See you there!', 'Η εγγραφή σου ολοκληρώθηκε. Τα λέμε εκεί!'),
                    'fields' => [
                        $field('text', 'full-name', $t('Full name', 'Ονοματεπώνυμο'), ['required' => true, 'width' => 'half']),
                        $field('email', 'email', 'Email', ['required' => true, 'width' => 'half']),
                        $field('number', 'guests', $t('Number of guests', 'Αριθμός καλεσμένων'), ['width' => 'third', 'default' => '1', 'min' => '1', 'max' => '10']),
                        $field('checkboxes', 'needs', $t('Anything we should know?', 'Κάτι που πρέπει να ξέρουμε;'), ['options' => [$t('Vegetarian meal', 'Χορτοφαγικό γεύμα'), $t('Accessibility needs', 'Ανάγκες προσβασιμότητας'), $t('I need parking', 'Χρειάζομαι parking')]]),
                        $field('textarea', 'notes', $t('Notes', 'Σημειώσεις'), ['rows' => 3]),
                    ],
                    'notifications' => $reply($t('Your registration', 'Η εγγραφή σου'), $t("Hello {full-name},\n\nYou are registered for {guests} guest(s).", "Γεια σου {full-name},\n\nΗ εγγραφή σου έγινε για {guests} άτομο/α.")),
                ];
            case 'support':
                return [
                    'id' => 'support', 'icon' => 'help', 'name' => $t('Support request', 'Αίτημα υποστήριξης'),
                    'description' => $t('A topic, how urgent it is and a description of the problem.', 'Θέμα, επείγον και περιγραφή του προβλήματος.'),
                    'title' => $t('Support request', 'Αίτημα υποστήριξης'), 'submit_label' => $t('Send request', 'Αποστολή αιτήματος'),
                    'success_message' => $t('Thank you. Our support team will reply to you soon.', 'Ευχαριστούμε. Η ομάδα υποστήριξης θα σου απαντήσει σύντομα.'),
                    'fields' => [
                        $field('text', 'full-name', $t('Full name', 'Ονοματεπώνυμο'), ['required' => true, 'width' => 'half']),
                        $field('email', 'email', 'Email', ['required' => true, 'width' => 'half']),
                        $field('select', 'topic', $t('Topic', 'Θέμα'), ['required' => true, 'width' => 'half', 'options' => [$t('Account', 'Λογαριασμός'), $t('Billing', 'Χρεώσεις'), $t('Technical problem', 'Τεχνικό πρόβλημα'), $t('Other', 'Άλλο')]]),
                        $field('radio', 'priority', $t('How urgent is it?', 'Πόσο επείγον είναι;'), ['width' => 'half', 'default' => 'normal', 'options' => ['low|' . $t('Low', 'Χαμηλό'), 'normal|' . $t('Normal', 'Κανονικό'), 'high|' . $t('Urgent', 'Επείγον')]]),
                        $field('textarea', 'message', $t('Describe the problem', 'Περίγραψε το πρόβλημα'), ['required' => true, 'rows' => 6]),
                    ],
                    'notifications' => $reply($t('We received your request', 'Λάβαμε το αίτημά σου'), $t("Hello {full-name},\n\nWe received your request about \"{topic}\" and will reply soon.", "Γεια σου {full-name},\n\nΛάβαμε το αίτημά σου για «{topic}» και θα απαντήσουμε σύντομα.")),
                ];
            case 'feedback':
                return [
                    'id' => 'feedback', 'icon' => 'heart', 'name' => $t('Feedback', 'Σχόλια'),
                    'description' => $t('A rating and a few words, with an optional name and email.', 'Βαθμολογία και λίγα λόγια, με προαιρετικό όνομα και email.'),
                    'title' => $t('Feedback', 'Σχόλια'), 'submit_label' => $t('Send feedback', 'Αποστολή σχολίων'),
                    'success_message' => $t('Thank you for your feedback.', 'Ευχαριστούμε για τα σχόλιά σου.'),
                    'fields' => [
                        $field('radio', 'rating', $t('How was your experience?', 'Πώς ήταν η εμπειρία σου;'), ['required' => true, 'options' => ['5|' . $t('Excellent', 'Εξαιρετική'), '4|' . $t('Good', 'Καλή'), '3|' . $t('Okay', 'Μέτρια'), '2|' . $t('Poor', 'Κακή'), '1|' . $t('Very poor', 'Πολύ κακή')]]),
                        $field('textarea', 'comments', $t('What could we do better?', 'Τι θα μπορούσαμε να κάνουμε καλύτερα;'), ['rows' => 5]),
                        $field('text', 'full-name', $t('Name (optional)', 'Όνομα (προαιρετικό)'), ['width' => 'half']),
                        $field('email', 'email', $t('Email (optional)', 'Email (προαιρετικό)'), ['width' => 'half']),
                    ],
                    'notifications' => ['enabled' => true, 'to' => '', 'reply_to_field' => 'email', 'auto_reply' => false],
                ];
        }
        return null;
    }
}
