package it.example.lab;

import com.amazonaws.services.lambda.runtime.Context;
import com.amazonaws.services.lambda.runtime.LambdaLogger;
import com.amazonaws.services.lambda.runtime.RequestHandler;

import java.math.BigDecimal;
import java.math.RoundingMode;

/**
 * Funzione stateless: l'output dipende solo dall'input.
 * Nessuno stato condiviso tra invocazioni.
 */
public class ImportiHandler implements RequestHandler<RichiestaImporti, RisultatoImporti> {

    @Override
    public RisultatoImporti handleRequest(RichiestaImporti richiesta, Context context) {
        LambdaLogger logger = context.getLogger();
        logger.log("RequestId=" + context.getAwsRequestId()
                + " tempoResiduoMs=" + context.getRemainingTimeInMillis());

        if (richiesta == null || richiesta.getImporti() == null || richiesta.getImporti().isEmpty()) {
            throw new IllegalArgumentException("Il campo 'importi' è obbligatorio e non può essere vuoto");
        }

        BigDecimal aliquota = richiesta.getAliquotaIva() != null
                ? richiesta.getAliquotaIva()
                : BigDecimal.ZERO;

        BigDecimal imponibile = richiesta.getImporti().stream()
                .reduce(BigDecimal.ZERO, BigDecimal::add)
                .setScale(2, RoundingMode.HALF_UP);

        BigDecimal iva = imponibile.multiply(aliquota)
                .divide(BigDecimal.valueOf(100), 2, RoundingMode.HALF_UP);

        RisultatoImporti risultato = new RisultatoImporti();
        risultato.setNumeroImporti(richiesta.getImporti().size());
        risultato.setImponibile(imponibile);
        risultato.setIva(iva);
        risultato.setTotale(imponibile.add(iva));
        risultato.setRequestId(context.getAwsRequestId());

        logger.log("Elaborati " + risultato.getNumeroImporti() + " importi, totale=" + risultato.getTotale());
        return risultato;
    }
}