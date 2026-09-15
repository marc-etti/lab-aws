# System prompt e parametri di generazione
# In questo esempio, utilizziamo il modello openai.gpt-oss-20b-1:0 
# per generare una risposta a una domanda posta dall'utente.
# Il prompt del sistema indica al modello di rispondere come un docente di cloud computing, in italiano e con un limite massimo di 120 parole.
# La temperatura è impostata a 0.2 per favorire risposte più coerenti e meno casuali, 
# mentre il numero massimo di token è fissato a 200 per garantire che la risposta non superi il limite stabilito.

from openai import OpenAI

client = OpenAI()

response = client.chat.completions.create(
    model="openai.gpt-oss-20b-1:0",
    messages=[
        {
            "role": "system",
            "content": (
                "Sei un docente di cloud computing. "
                "Rispondi in italiano e in massimo 120 parole."
            ),
        },
        {
            "role": "user",
            "content": "Confronta Amazon Bedrock e Amazon SageMaker AI.",
        },
    ],
    temperature=0.2,
    max_tokens=200,
)

print(response.choices[0].message.content)