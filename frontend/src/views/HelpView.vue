<template>
  <section class="stack">
    <article class="card hero">
      <p class="eyebrow">Hilfe</p>
      <h2>So ist die App aufgebaut</h2>
      <p>
        Diese Finanz-App trennt bewusst zwischen Übersicht, Buchungen, Auswertung und Import, damit
        du schneller arbeiten und deine Daten besser verstehen kannst.
      </p>
    </article>

    <article class="card">
      <h3>Navigation</h3>
      <ul>
        <li>
          <strong>Dashboard:</strong> Gesamtüberblick über Konten, Monatsentwicklung und letzte
          Importe
        </li>
        <li><strong>Buchungen:</strong> alle Umsätze durchsuchen, filtern und kategorisieren</li>
        <li><strong>Auswertung:</strong> Kategorien und Zeiträume analysieren</li>
        <li><strong>Import:</strong> CSV hochladen, prüfen und Import-Verlauf ansehen</li>
        <li><strong>Hilfe:</strong> Funktionsbeschreibung, Hinweise und Projektstatus</li>
      </ul>
    </article>

    <article class="card">
      <h3>FAQ: Was tun bei falscher Kategorisierung?</h3>
      <div class="faq-item">
        <p>
          <strong>Eine Buchung wurde falsch eingeordnet – wie korrigiere ich das?</strong>
        </p>
        <ol>
          <li>Öffne <strong>Kategorien → Regeln</strong> und suche die betroffene Kategorie.</li>
          <li>
            Prüfe auf zu allgemeine Suchstrings wie <code>abrechnung</code> oder
            <code>zahlung</code>.
          </li>
          <li>
            Mach die Regel präziser, z. B. <code>gehaltsabrechnung</code> statt nur
            <code>abrechnung</code>.
          </li>
          <li>Lege bei Bedarf eine spezifischere Gegenregel mit höherer Priorität an.</li>
          <li>
            Für Buchungen wie <code>KREDITKARTENABRECHNUNG</code> ist meist
            <strong>Transfer</strong> sinnvoll.
          </li>
          <li>Teste die Regel und führe danach die automatische Kategorisierung erneut aus.</li>
        </ol>
      </div>
    </article>

    <article class="card">
      <h3>FAQ: Wie gehen wir mit Visa, Zahlungsquellen und Transfers um?</h3>
      <div class="faq-item">
        <p>
          <strong>Warum ist eine Kreditkartenabrechnung keine normale Ausgabe?</strong>
        </p>
        <ul>
          <li>
            Die eigentliche Ausgabe entsteht beim Händler, z. B. <code>REWE</code> oder
            <code>DB</code>.
          </li>
          <li>
            Die spätere Buchung <code>KREDITKARTENABRECHNUNG</code> ist nur der Ausgleich zwischen
            Girokonto und Visa.
          </li>
          <li>Solche Bewegungen sollten als <strong>Transfer</strong> gekennzeichnet werden.</li>
          <li>In der Auswertung zählen sie dann nicht doppelt als Konsumausgabe.</li>
        </ul>
        <p>
          <strong>Was ist die beste Regel für solche technischen Buchungen?</strong>
        </p>
        <ul>
          <li><code>kreditkartenabrechnung</code> → <strong>Transfer</strong></li>
          <li><code>ausgleich kreditkarte</code> → <strong>Transfer</strong></li>
          <li><code>visa abrechnung</code> → <strong>Transfer</strong></li>
        </ul>
        <p>
          <strong>Wie sollte das später in der App sichtbar sein?</strong>
        </p>
        <ul>
          <li>In <strong>Buchungen</strong> sollte klar <strong>Transfer</strong> erscheinen.</li>
          <li>
            In der <strong>Auswertung</strong> sollen Transfers standardmäßig von echten Ausgaben
            getrennt werden.
          </li>
        </ul>
      </div>
    </article>

    <article class="card">
      <h3>FAQ: Wie erfasse ich Bargeld-Ausgaben?</h3>
      <div class="faq-item">
        <p><strong>Wo trage ich eine bar bezahlte Rechnung ein?</strong></p>
        <ol>
          <li>
            Öffne <strong>Buchungen</strong> und klicke auf <strong>+ Bargeld erfassen</strong>.
          </li>
          <li>
            Trage Datum, Betrag, Gegenstelle und optional eine Beschreibung ein, z. B.
            <code>Autowerkstatt Müller</code>.
          </li>
          <li>
            Ohne Kategorieauswahl greifen deine <strong>Regeln</strong> automatisch, sonst wählst du
            die Kategorie direkt aus.
          </li>
          <li>
            Die Buchung landet im Konto <strong>Bargeld</strong> und zählt ab sofort in Dashboard
            und Auswertung mit.
          </li>
        </ol>
        <p><strong>Woher kommt das Geld in der Bargeldkasse?</strong></p>
        <ul>
          <li>
            Sobald eine Abhebung am Girokonto als <strong>Transfer</strong> erkannt ist, entsteht
            automatisch eine Gegenbuchung im Bargeldkonto.
          </li>
          <li>
            Auch Bargeldauszahlungen beim Einkauf werden erkannt und als Teilbetrag in die Kasse
            übertragen.
          </li>
          <li>
            Beide Seiten sind als Transfer markiert und werden deshalb nicht als Ausgabe gezählt –
            gezählt wird erst, wofür du das Bargeld ausgibst.
          </li>
        </ul>
        <p><strong>Kann ich eine erfasste Buchung wieder ändern?</strong></p>
        <ul>
          <li>
            Ja: Wähle die Buchung in der Liste aus und nutze im Detailbereich
            <strong>Bearbeiten</strong>.
          </li>
          <li>
            Importierte Umsätze bleiben bewusst unveränderlich – nur selbst erfasste Buchungen
            lassen sich ändern oder löschen.
          </li>
        </ul>
      </div>
    </article>

    <article class="card">
      <h3>Projektstatus</h3>
      <ul>
        <li>✅ Authentifizierung mit Registrierung, Login und geschütztem Nutzerbereich</li>
        <li>✅ CSV-Import für DKB Giro, DKB Visa und PayPal mit duplikatsicherem Einlesen</li>
        <li>
          ✅ Kategorien, Regeln, manuelle Korrekturen sowie Standardregeln für häufige Ausgaben
        </li>
        <li>
          ✅ Dashboard, Buchungsliste und Auswertung inklusive Split-Buchungen und Transfer-Logik
        </li>
        <li>✅ Visa-/PayPal-Verknüpfungen und Budgetmonat-Logik für eine bessere Einordnung</li>
        <li>
          ✅ Bargeldkonto mit manueller Erfassung und automatischer Gegenbuchung bei Abhebungen
        </li>
        <li>
          ✅ Performance-Optimierungen für die Auswertung sowie lokale Rechtstexte im Frontend
        </li>
        <li>
          🔜 Nächste Schritte: Rechtstexte final prüfen, Livegang wieder aktivieren und weitere
          Automatisierung ausbauen
        </li>
      </ul>
    </article>
  </section>
</template>

<style scoped>
.stack {
  display: grid;
  gap: 1rem;
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

ul,
ol {
  padding-left: 1.1rem;
}

li {
  margin-bottom: 0.45rem;
}

.faq-item p {
  margin-top: 0;
}

li::marker {
  color: var(--color-accent-strong);
}
</style>
