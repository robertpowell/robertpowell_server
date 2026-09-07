<?php /* Privacy policy body. Needs $contact_url. Wrapped by the including page. */ ?>
<article class="card">
  <h2>In short</h2>
  <p>This site sets no cookies and runs no third-party analytics or tracking scripts. Like every web server it keeps an access log, and if you use the contact form your message and some details about where it came from are kept so it can be answered. That is all.</p>

  <h2>What is collected, and for how long</h2>
  <table>
    <tr><th>Data</th><th>When</th><th>Kept for</th></tr>
    <tr><td>IP address</td><td>Every request</td><td rowspan="4">Up to 7 years</td></tr>
    <tr><td>Browser and device details (the user-agent string, preferred language)</td><td>Every request</td></tr>
    <tr><td>Approximate location derived from the IP address (country, region, town; never more precise than a postcode district)</td><td>Every request, and with each contact message</td></tr>
    <tr><td>Pages visited, time of visit, referring page, response status</td><td>Every request</td></tr>
    <tr><td>Contact messages: your name, email address, subject and message text, together with the details above at the time of sending</td><td>When you use the contact form</td><td>Up to 7 years</td></tr>
  </table>
  <p>Access-log data is retained for a period of up to 7 years for usability and performance purposes: understanding how the site is used, keeping it fast, and diagnosing faults and abuse. Contact messages are retained so that correspondence can be answered and referred back to.</p>

  <h2>Why this is lawful</h2>
  <p>Under UK data protection law the basis for keeping access logs and abuse records is legitimate interests: running a secure, working website. The basis for handling a contact message is that you asked for a reply. Nothing here is used for advertising, profiling or automated decisions, and nothing is sold or shared for others' marketing.</p>

  <h2>Who else sees it</h2>
  <ul>
    <li><strong>Location lookup.</strong> To turn an IP address into an approximate place, the address is sent to a third-party geolocation service. Only the IP address is sent; the reply is a town, region and country estimate.</li>
    <li><strong>Email.</strong> Contact messages are delivered to a mailbox at a third-party email provider. The email carries your message and the sender details listed above.</li>
    <li><strong>Hosting.</strong> The site runs on a server hosted by a third-party provider in the European Union. Data is not otherwise transferred outside the UK or EU.</li>
    <li><strong>Abuse prevention.</strong> Addresses that probe or attack the site are blocked automatically and the block list is kept for the same period as the logs.</li>
  </ul>

  <h2>Your rights</h2>
  <p>You can ask what is held about you, ask for it to be corrected or deleted, or object to it being kept. Use the <a href="<?= htmlspecialchars($contact_url, ENT_QUOTES) ?>">contact form</a>; you will get a reply from the address that handles this site. You also have the right to complain to the UK data protection regulator.</p>

  <h2>Contact form specifics</h2>
  <p>The form uses a slide-to-send control and rate limits instead of a third-party CAPTCHA, so nothing about you is sent to a CAPTCHA provider. A short-lived token is embedded in the page to confirm the message came from the form; it is not a cookie and it identifies the page load, not you. Your email address is used only to reply to you.</p>

  <p class="muted">Last updated 4 September 2026.</p>
</article>
