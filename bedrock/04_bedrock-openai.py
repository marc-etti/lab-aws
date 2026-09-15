# Streaming della risposta
# In questo esempio, utilizziamo il modello openai.gpt-oss-20b-1:0 per generare una risposta a una domanda posta dall'utente.
# La risposta viene restituita in streaming, consentendo di visualizzare il testo man mano che viene generato dal modello.
# Il streaming consente di ottenere una risposta più rapida e di migliorare l'esperienza utente.
# La temperatura è impostata a 0.2 per favorire risposte più coerenti e meno casuali,
# mentre il numero massimo di token è fissato a 250 per garantire che la risposta non superi il limite stabilito.

from openai import OpenAI

client = OpenAI()

stream = client.chat.completions.create(
    model="openai.gpt-oss-20b-1:0",
    messages=[
        {
            "role": "user",
            "content": "Spiega in modo sintetico che cos'è una Knowledge Base.",
        }
    ],
    max_tokens=250,
    stream=True,
)

for chunk in stream:
    content = chunk.choices[0].delta.content
    if content:
        print(content, end="", flush=True)

print()