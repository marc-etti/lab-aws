package it.example.lab;

import java.math.BigDecimal;

public class RisultatoImporti {
    private int numeroImporti;
    private BigDecimal imponibile;
    private BigDecimal iva;
    private BigDecimal totale;
    private String requestId;

    public int getNumeroImporti() { return numeroImporti; }
    public void setNumeroImporti(int n) { this.numeroImporti = n; }

    public BigDecimal getImponibile() { return imponibile; }
    public void setImponibile(BigDecimal v) { this.imponibile = v; }

    public BigDecimal getIva() { return iva; }
    public void setIva(BigDecimal v) { this.iva = v; }

    public BigDecimal getTotale() { return totale; }
    public void setTotale(BigDecimal v) { this.totale = v; }

    public String getRequestId() { return requestId; }
    public void setRequestId(String requestId) { this.requestId = requestId; }
}