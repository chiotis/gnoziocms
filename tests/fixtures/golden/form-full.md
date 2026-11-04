---
title: 'Φόρμα δοκιμής'
status: published
visible: true
created: <time>
translation_id: <generated>
fields:
  -
    type: email
    name: email-address
    label: 'Email Address'
    required: true
    placeholder: you@example.test
  -
    type: select
    name: topic
    label: Topic
    options:
      - A
      - B
      - C
    default: B
  -
    type: textarea
    name: message
    label: Message
    help: 'Tell us more'
    rows: 6
  -
    type: number
    name: qty
    label: Qty
    min: '1'
    max: '9'
    step: '2'
  -
    type: text
    name: ok
    label: 'Falls back to text'
notifications:
  enabled: true
  to: team@example.test
  subject: 'New message'
  reply_to_field: email-address
  cc: cc@example.test
  bcc: ''
  auto_reply: true
  auto_reply_include: false
  attach_files: false
  attach_max_mb: 10.0
  auto_reply_subject: Thanks
  auto_reply_message: 'We got it'
success_message: Sent!
submit_label: 'Send it'
redirect_url: /thanks
store_submissions: true
antispam:
  honeypot: website
  rate_limit_seconds: 30
---

Intro
