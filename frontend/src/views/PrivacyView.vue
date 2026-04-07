<script setup lang="ts">
import { legalContact } from '@/lib/legal'
</script>

<template>
  <section class="stack legal-page">
    <article class="card hero">
      <p class="eyebrow">Datenschutz</p>
      <h2>Informationen zur Datenverarbeitung</h2>
      <p>
        Diese Datenschutzerklärung beschreibt die wesentlichen Verarbeitungen in
        <strong>Finanzcockpit</strong>. Bitte prüfe die Inhalte vor einem öffentlichen Livegang und
        ergänze ggf. fehlende organisatorische Details.
      </p>
      <p class="meta">Stand: 7. April 2026</p>
    </article>

    <article class="card">
      <h3>1. Verantwortlicher</h3>
      <p>
        <strong :class="{ placeholder: !legalContact.ownerNameConfigured }">
          {{ legalContact.ownerName }} </strong
        ><br />
        <span :class="{ placeholder: !legalContact.addressLine1Configured }">
          {{ legalContact.addressLine1 }} </span
        ><br />
        <span :class="{ placeholder: !legalContact.addressLine2Configured }">
          {{ legalContact.addressLine2 }} </span
        ><br />
        E-Mail:
        <a :href="legalContact.emailHref" :class="{ placeholder: !legalContact.emailConfigured }">
          {{ legalContact.email }}
        </a>
      </p>
    </article>

    <article class="card">
      <h3>2. Zweck der Verarbeitung</h3>
      <ul>
        <li>Bereitstellung eines geschützten Benutzerkontos</li>
        <li>Import und Verarbeitung von Finanz- und Buchungsdaten</li>
        <li>Kategorisierung, Auswertung und Visualisierung von Transaktionen</li>
        <li>Betrieb, Sicherheit und technische Stabilität der Anwendung</li>
      </ul>
    </article>

    <article class="card">
      <h3>3. Verarbeitete Daten</h3>
      <ul>
        <li>Kontaktdaten wie Name und E-Mail-Adresse bei Registrierung und Login</li>
        <li>hochgeladene CSV-Dateien mit Finanz- und Transaktionsdaten</li>
        <li>abgeleitete Kategorien, Regeln, Auswertungen und Importhistorie</li>
        <li>technische Zugriffsdaten und Server-Logs zur Sicherstellung des Betriebs</li>
      </ul>
    </article>

    <article class="card">
      <h3>4. Rechtsgrundlagen</h3>
      <ul>
        <li>
          <strong>Art. 6 Abs. 1 lit. b DSGVO</strong> für die Bereitstellung der registrierten
          Anwendung und die Verarbeitung auf Nutzerwunsch
        </li>
        <li>
          <strong>Art. 6 Abs. 1 lit. f DSGVO</strong> für Sicherheit, Fehleranalyse und Schutz vor
          Missbrauch
        </li>
      </ul>
    </article>

    <article class="card">
      <h3>5. Hosting und technische Bereitstellung</h3>
      <p>
        Die Anwendung wird auf einem selbst verwalteten Server bei
        <strong>Hetzner Online GmbH</strong>, Industriestraße 25, 91710 Gunzenhausen, Deutschland
        betrieben.
      </p>
      <p>
        Im Rahmen des Hostings verarbeitet Hetzner technische Infrastruktur- und Verbindungsdaten,
        soweit dies für den sicheren Betrieb der Server, Netzwerke und Systeme erforderlich ist.
        Dazu können insbesondere IP-Adresse, Zeitstempel, aufgerufene URL, Statuscode sowie
        Informationen zum verwendeten Browser oder Endgerät gehören.
      </p>
      <p>
        Die Verarbeitung erfolgt zur Bereitstellung, Stabilität und Sicherheit der Anwendung auf
        Grundlage von <strong>Art. 6 Abs. 1 lit. f DSGVO</strong>. Sofern rechtlich erforderlich,
        erfolgt das Hosting auf Basis eines Vertrags zur Auftragsverarbeitung.
      </p>
    </article>

    <article class="card">
      <h3>6. Lokale Speicherung im Browser</h3>
      <p>Die Anwendung nutzt aktuell keine Marketing- oder Tracking-Cookies.</p>
      <p>
        Im Browser werden jedoch technisch erforderliche Informationen gespeichert, insbesondere:
      </p>
      <ul>
        <li><code>finanzen.auth.token</code> zur Aufrechterhaltung der Anmeldung</li>
        <li><code>finanzen-theme</code> zur Speicherung des gewählten Hell-/Dunkelmodus</li>
        <li>
          <code>finanzen.privacy.notice.v1</code> zur Speicherung, dass der Datenschutzhinweis
          bestätigt wurde
        </li>
      </ul>
      <p>
        Diese Speicherung dient ausschließlich der Funktionalität und Transparenz der Anwendung und
        fällt derzeit nicht unter werbliche oder analysierende Tracker.
      </p>
    </article>

    <article class="card">
      <h3>7. Speicherdauer</h3>
      <p>
        Konto- und Finanzdaten werden grundsätzlich so lange gespeichert, wie dies für die Nutzung
        der Anwendung erforderlich ist oder bis eine Löschung des Benutzerkontos bzw. einzelner
        Inhalte veranlasst wird.
      </p>
      <p>
        Technische Server- und Fehlerprotokolle werden nur für einen begrenzten Zeitraum
        vorgehalten, soweit dies für den sicheren und stabilen Betrieb erforderlich ist, in der
        Regel <strong>bis zu 14 Tage</strong>, und danach gelöscht oder überschrieben, sofern keine
        längere Aufbewahrung zur Aufklärung eines konkreten Sicherheits- oder Missbrauchsfalls
        notwendig ist.
      </p>
      <p>
        Sofern Systemsicherungen oder Backups erstellt werden, erfolgt deren Aufbewahrung nur
        zeitlich begrenzt und im Rahmen eines rotierenden Backup-Konzepts. Die Sicherungen werden
        gelöscht oder überschrieben, sobald sie für die Wiederherstellung nicht mehr erforderlich
        sind.
      </p>
      <p>
        Lokal im Browser gespeicherte Informationen bleiben erhalten, bis du dich ausloggst,
        Einstellungen änderst oder den lokalen Speicher deines Browsers löschst.
      </p>
    </article>

    <article class="card">
      <h3>8. Rechte betroffener Personen</h3>
      <ul>
        <li>Auskunft über die verarbeiteten personenbezogenen Daten</li>
        <li>Berichtigung unrichtiger Daten</li>
        <li>Löschung oder Einschränkung der Verarbeitung</li>
        <li>Widerspruch gegen bestimmte Verarbeitungen</li>
        <li>Datenübertragbarkeit im Rahmen der gesetzlichen Vorgaben</li>
        <li>Beschwerde bei einer Datenschutzaufsichtsbehörde</li>
      </ul>
    </article>

    <article class="card">
      <h3>9. Kontakt zum Datenschutz</h3>
      <p>
        Bei Fragen zur Verarbeitung personenbezogener Daten kannst du dich an
        <a :href="legalContact.emailHref" :class="{ placeholder: !legalContact.emailConfigured }">
          {{ legalContact.email }}
        </a>
        wenden.
      </p>
    </article>
  </section>
</template>

<style scoped>
.stack {
  display: grid;
  gap: 1rem;
}

.legal-page {
  max-width: 900px;
}

.card {
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
}

.hero {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.eyebrow {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.meta {
  color: var(--color-text-muted);
  font-size: 0.92rem;
}

.placeholder {
  color: var(--color-text-muted);
  font-style: italic;
}

ul {
  padding-left: 1.1rem;
}

li {
  margin-bottom: 0.45rem;
}

a {
  color: var(--color-accent-strong);
}
</style>
