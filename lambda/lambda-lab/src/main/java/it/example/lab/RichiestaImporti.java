package it.example.lab;

import java.math.BigDecimal;
import java.util.List;

public class RichiestaImporti {
    private List<BigDecimal> importi;
    private BigDecimal aliquotaIva; // es. 22 per il 22%

    public List<BigDecimal> getImporti() { return importi; }
    public void setImporti(List<BigDecimal> importi) { this.importi = importi; }

    public BigDecimal getAliquotaIva() { return aliquotaIva; }
    public void setAliquotaIva(BigDecimal aliquotaIva) { this.aliquotaIva = aliquotaIva; }
}