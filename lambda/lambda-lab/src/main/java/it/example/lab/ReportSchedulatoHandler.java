package it.example.lab;

import com.amazonaws.services.lambda.runtime.Context;
import com.amazonaws.services.lambda.runtime.LambdaLogger;
import com.amazonaws.services.lambda.runtime.RequestHandler;

import java.time.Instant;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.Map;

/**
 * Job schedulato: sostituisce una riga di crontab.
 * Qui simula un "report giornaliero"; in un caso reale leggerebbe da DB/S3 ecc.
 */
public class ReportSchedulatoHandler implements RequestHandler<Map<String, Object>, String> {

    private static final DateTimeFormatter FMT =
            DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss").withZone(ZoneId.of("Europe/Rome"));

    @Override
    public String handleRequest(Map<String, Object> evento, Context context) {
        LambdaLogger logger = context.getLogger();

        String ambiente = System.getenv().getOrDefault("AMBIENTE", "demo");
        boolean simulaErrore = Boolean.parseBoolean(System.getenv().getOrDefault("SIMULA_ERRORE", "false"));

        logger.log("Job avviato. RequestId=" + context.getAwsRequestId()
                + " ambiente=" + ambiente
                + " sorgente=" + evento.getOrDefault("source", "invocazione manuale")
                + " ora=" + FMT.format(Instant.now()));

        if (simulaErrore) {
            // Dopo il fallimento, per le invocazioni asincrone Lambda ritenta automaticamente (2 volte)
            throw new IllegalStateException("Errore simulato per la demo dei retry");
        }

        // --- Logica del job (qui solo una simulazione) ---
        int righeElaborate = 42;
        logger.log("Report generato: " + righeElaborate + " righe elaborate");

        return "OK - " + righeElaborate + " righe";
    }
}