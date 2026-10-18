---
title: 'Φόρμα Επικοινωνίας'
status: published
visible: true
translation_id: f0a1b2c3d4e5f678
submit_label: 'Αποστολή Μηνύματος'
success_message: 'Ευχαριστούμε. Το μήνυμά σας καταχωρήθηκε και θα επικοινωνήσουμε μαζί σας σύντομα.'
store_submissions: true
notifications:
  enabled: true
  to: hello@gnoziocms.test
  subject: 'Νέα υποβολή φόρμας επικοινωνίας'
  reply_to_field: email
  auto_reply: true
  auto_reply_include: true
  auto_reply_subject: 'Λάβαμε το μήνυμά σας'
  auto_reply_message: 'Ευχαριστούμε για την επικοινωνία. Η ομάδα μας θα απαντήσει το συντομότερο δυνατό.'
antispam:
  honeypot: website
  rate_limit_seconds: 20
fields:
  -
    type: text
    name: full_name
    label: Ονοματεπώνυμο
    required: true
    placeholder: 'Το ονοματεπώνυμό σας'
    translations:
      en:
        label: 'Full Name'
        placeholder: 'Your full name'
  -
    type: email
    name: email
    label: Email
    required: true
    placeholder: name@example.com
    translations:
      en:
        label: Email
        placeholder: name@example.com
  -
    type: tel
    name: phone
    label: Τηλέφωνο
    placeholder: '+30 69X XXX XXXX'
    translations:
      en:
        label: Phone
        placeholder: '+30 69X XXX XXXX'
  -
    type: text
    name: company
    label: Εταιρεία
    placeholder: 'Επωνυμία εταιρείας'
    translations:
      en:
        label: Company
        placeholder: 'Company name'
  -
    type: select
    name: service
    label: 'Ενδιαφέρομαι για'
    required: true
    options:
      - strategy|Στρατηγική
      - 'design-build|Design & Build'
      - 'project-management|Διαχείριση Έργου'
      - other|Άλλο
    translations:
      en:
        label: 'Interested In'
        options:
          strategy: Strategy
          design-build: 'Design & Build'
          project-management: 'Project Management'
          other: Other
  -
    type: textarea
    name: message
    label: Μήνυμα
    required: true
    rows: 6
    placeholder: 'Πείτε μας λίγα λόγια για το έργο σας'
    translations:
      en:
        label: Message
        placeholder: 'Tell us a few details about your project'
  -
    type: checkbox
    name: consent
    label: 'Συμφωνώ με την επεξεργασία των στοιχείων μου για σκοπούς επικοινωνίας.'
    required: true
    translations:
      en:
        label: 'I agree to the processing of my information for communication purposes.'
translations:
  en:
    title: 'Contact Form'
    submit_label: 'Send Message'
    success_message: 'Thank you. Your message has been submitted and our team will contact you shortly.'
    notifications:
      subject: 'New contact form submission'
      auto_reply_subject: 'We received your message'
      auto_reply_message: 'Thanks for contacting us. Our team will get back to you as soon as possible.'
---


