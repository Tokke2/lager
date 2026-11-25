# Valda förslag 1, 2, 3, 7, 8 och 25

Förslagsnumren hänvisar till `23_25-nya-ideer.md`. Lösningarna nedan är
första avgränsade versioner; ingen av dem gör automatiska uttag eller flyttar
saldo till ett annat arbetsställe.

1. **Materialåtgång:** Under Uttag finns en kalkylator för arbetsmängd och
   material per arbetsenhet. Den visar beräknad åtgång och avrundning till hela
   enheter. Kontrollera själv artikel, enhet och saldo; kalkylatorn fyller inte
   i uttagsblanketten och den registrerar inget uttag.
2. **Öppnad förpackning:** Vid skapande/redigering av artikel kan en gemensam
   fritextnotering ange arbetsställe, förpackning och ungefärlig mängd. Den
   visas med senaste ändringstid på artikelkortet. Den är inte saldo, uppdateras
   inte automatiskt och kan bli inaktuell. Ändring av befintlig artikel kräver
   admin. Det finns ännu inte separata strukturerade poster per förpackning.
3. **Lokal ordlista:** Admin kan under Systemstatus ange term och förklaring
   för artikelnummer, enhet, kategori, miniminivå och arbetsställe. Texten visas
   vid relevanta fält på artikelformuläret samt vid arbetsställe i uttag och
   utlåning. Listan gäller inte automatiskt alla appens fält eller sidor.
7. **Returplats:** En valfri önskad platsbeskrivning anges vid utlåning. Om den
   finns måste faktisk plats anges för varje delåterlämning. Kvittot och
   historiken visar om texterna stämmer; jämförelsen ignorerar skiftläge men
   känner inte till om två olika beskrivningar avser samma hylla. Saldot
   återförs som tidigare till utlåningens ursprungliga arbetsställe. Detta är
   inte en flytt till ett annat arbetsställe eller en ny hyllplacering.
8. **Räkneinstruktion:** Ange en valfri instruktion på artikeln. Den visas vid
   artikeln i den öppna inventeringen. Den är aktuell artikelinformation,
   inte en fryst del av inventeringens resultat; ändringar spåras i historiken.
25. **Pausad kamera:** Skanna-sidan visar status när sidan döljs eller
   kameraströmmen pausas/avslutas, och erbjuder manuell återstart. Kameran
   startas inte automatiskt när sidan visas igen. Manuell kodinmatning finns kvar.

Skrivningar använder befintlig autentisering och CSRF-skydd; artikel- och
utlåningsändringar följer Lagers låsning, transaktioner och historik. Existerande
artiklar och lån fungerar även när de nya fälten saknas.
