"""Explainable operational insights. Receives aggregates only, never clinical data."""
from fastapi import FastAPI
from pydantic import BaseModel, Field
app = FastAPI(title="Loreto · Inteligência operacional", version="1.0.0")
class Specialty(BaseModel):
    name: str
    capacity: int = Field(ge=0)
    booked: int = Field(ge=0)
class Metrics(BaseModel):
    specialties: list[Specialty]
    waiting: int = Field(ge=0)
@app.get('/health')
def health():
    return {'status': 'ok'}
@app.post('/insights')
def insights(metrics: Metrics):
    result = []
    for row in metrics.specialties:
        occupancy = round(100 * row.booked / row.capacity) if row.capacity else 0
        if row.capacity and occupancy >= 85:
            result.append({'title': f'{row.name}: agenda concorrida', 'text': f'{occupancy}% das vagas estão ocupadas no período. Avalie oferecer novas datas.', 'tone': 'warning'})
        elif row.capacity and occupancy < 40:
            result.append({'title': f'Vagas em {row.name}', 'text': f'{row.capacity - row.booked} vagas disponíveis no período para novos agendamentos.', 'tone': 'info'})
    if metrics.waiting:
        result.insert(0, {'title': 'Fila de atendimento', 'text': f'{metrics.waiting} pessoas aguardam. Mantenha a chamada por ordem de chegada.', 'tone': 'info'})
    return {'method': 'Regras explicáveis sobre ocupação e fila; sem previsão clínica.', 'insights': result[:4]}
