# WooCommerce 24h Orders Email Report

**Versione 1.1.0**

Plugin per WooCommerce che invia un report email degli ordini creati nelle ultime 24 ore.

## Funzioni

- Destinatari multipli modificabili dal backoffice.
- Orario giornaliero configurabile.
- Finestra mobile esatta di 24 ore rispetto all'esecuzione.
- Stati ordine selezionabili.
- Nome e cognome + email cliente.
- Totale ordine.
- Metodo di spedizione.
- Metodo di pagamento.
- Prodotti e variazioni.
- SKU.
- Quantità.
- Invio manuale ai destinatari.
- Invio di test all'email amministrativa.
- Compatibile con l'API CRUD degli ordini WooCommerce e quindi con HPOS.
- Nessuna query SQL diretta.
- Supporto al fuso orario WordPress.
- Opzione per non inviare email quando non ci sono ordini.

## Installazione

1. Caricare `wc-24h-orders-email-report.zip` da Plugin > Aggiungi nuovo > Carica plugin.
2. Attivare il plugin.
3. Aprire WooCommerce > Report ordini 24 ore.
4. Inserire i destinatari e l'orario.
5. Selezionare gli stati ordine da includere.
6. Salvare.
7. Usare "Invia report di test" per verificare la configurazione.

## Scheduling

La versione 1.1 utilizza **Action Scheduler**, il job queue dell'ecosistema WooCommerce, quando disponibile.

Vantaggi:
- job persistente nel database;
- prevenzione dei duplicati;
- storico delle esecuzioni;
- stato pending/complete/failed;
- log degli errori;
- possibilità di esecuzione manuale dalla schermata Scheduled Actions;
- controllo automatico e riprogrammazione se il job viene perso;
- fallback automatico a WP-Cron se Action Scheduler non è disponibile.

Per un'esecuzione strettamente controllata dal server è comunque consigliabile utilizzare WP-CLI/cron di sistema per eseguire la coda.


## Note

Il report cerca gli ordini in base alla data di creazione, non alla data di pagamento.

Il plugin usa `wc_get_orders()` invece di query SQL dirette, seguendo le best practice WooCommerce e mantenendo la compatibilità con HPOS.
