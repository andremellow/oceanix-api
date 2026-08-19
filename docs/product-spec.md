# Oceanix Training Compliance

## Especificação funcional e técnica consolidada

**Status:** definição do MVD e preparação arquitetural para evoluções futuras
**Stack principal:** Laravel + Livewire
**Identidade:** WorkOS
**Vídeo recomendado no MVD:** Cloudflare Stream
**Data da consolidação:** 18 de agosto de 2026

> Este documento é a fonte de verdade do produto e permanece em português, exatamente como
> fornecido. Todo o restante da documentação, do código e da interface é escrito em inglês.

---

## 1. Visão do produto

Oceanix Training Compliance é uma plataforma corporativa de treinamento e conformidade. Seu objetivo não é apenas disponibilizar vídeos e quizzes, mas demonstrar, de forma auditável:

- quem precisava realizar determinado treinamento;
- por qual regra ou atribuição individual;
- qual versão exata do conteúdo foi apresentada;
- quando e como o usuário interagiu com o treinamento;
- quais respostas e tentativas ocorreram;
- quando a obrigação foi concluída, venceu ou renovada;
- qual certificado foi emitido.

O sistema deve ser simples para o funcionário e oferecer ao administrativo uma visão operacional clara sobre pendências e risco de não conformidade.

### Princípios arquiteturais

1. Cursos publicados são versionados e preservados historicamente.
2. Uma regra de treinamento é diferente de uma obrigação individual materializada.
3. Tentativas e eventos de compliance nunca são sobrescritos.
4. O estado atual pode ser otimizado para leitura, mas a evidência histórica é append-only.
5. Identidade corporativa fica desacoplada das regras de negócio.
6. O provedor de vídeo fica atrás de uma abstração substituível.
7. A arquitetura nasce preparada para sincronização offline idempotente, sem implementar o modo offline no MVD.

---

## 2. Escopo do MVD

O MVD inclui:

- aplicação web responsiva em Laravel + Livewire;
- autenticação corporativa com WorkOS;
- usuários, departamentos e funções com relacionamentos N:N;
- criação, edição, publicação e versionamento de cursos;
- lessons com vídeo e avaliação;
- questões de escolha única ou múltipla;
- controle de tentativas, aprovação, reprovação e reassistência;
- Training Requirements direcionados por setor e/ou função;
- recorrência configurável por requirement;
- materialização de assignments individuais;
- assignments manuais, sem requirement;
- trilha append-only de eventos de compliance;
- dashboard administrativo e dashboard do funcionário;
- notificações e lembretes;
- certificados verificáveis;
- vídeo privado via Cloudflare Stream e tokens temporários;
- controles e processos básicos de LGPD.

### Fora do MVD, mas previsto no desenho

- aplicativo nativo para iPad;
- download e execução offline de treinamentos;
- sincronização posterior de eventos offline;
- importação de mobilization schedule ou jobs;
- Oceanix Training Station em dispositivos compartilhados;
- DRM offline;
- watermark dinâmico por usuário/sessão;
- conteúdo de lesson além de vídeo, como PDF, texto, checklist e acknowledgment.

---

## 3. Arquitetura de aplicação

### Backend e interface

- **Laravel** concentra domínio, autorização, jobs, filas, notificações, integrações e API.
- **Livewire** entrega uma experiência administrativa rica e app-like sem exigir uma SPA separada.
- A interface do funcionário deve ser responsiva e adequada ao uso em desktop e iPad pelo navegador.
- Jobs assíncronos cuidam de provisionamento, materialização de assignments, recorrência, notificações, certificados e integrações.

### Infraestrutura sugerida

- Laravel Cloud para aplicação, banco, cache, filas e object storage;
- banco relacional como fonte transacional de verdade;
- object storage para certificados e assets próprios;
- Cloudflare Stream para ingestão, encoding, CDN e playback de vídeo;
- provedor de e-mail integrado às filas do Laravel.

### Separação por domínios

```text
Identidade e Pessoas
    ↓
Estrutura Organizacional
    ↓
Conteúdo e Versionamento
    ↓
Requirements e Assignments
    ↓
Execução, Tentativas e Eventos
    ↓
Certificados, Notificações e Reporting
```

---

## 4. Identidade e acesso com WorkOS

O WorkOS responde por autenticação e identidade corporativa. O banco Oceanix responde pelas regras de treinamento.

### Responsabilidades do WorkOS

- login corporativo/SSO;
- AuthKit, conforme a integração escolhida;
- futura integração com Microsoft Entra ID ou Google Workspace;
- futuro Directory Sync/SCIM para provisionar, atualizar e desativar usuários.

### Responsabilidades da Oceanix

- departamentos e funções;
- público-alvo dos requirements;
- assignments e prazos;
- progresso, tentativas e compliance;
- certificados e relatórios;
- autorização de acesso ao conteúdo.

O usuário local deve existir mesmo quando provisionado pelo WorkOS. Regras de negócio não devem depender diretamente dos grupos ou da disponibilidade do provedor de identidade.

### Perfis mínimos

- **Employee:** acessa apenas os próprios assignments, histórico e certificados.
- **Training Admin:** administra cursos, requirements, assignments, pessoas e relatórios.
- **Auditor/Viewer:** consulta evidências e relatórios sem alterar conteúdo ou regras.
- **System Integration:** identidade técnica restrita a sincronizações autorizadas.

---

## 5. Pessoas e estrutura organizacional

Departamentos e funções são relações N:N. Isso permite que uma função exista em vários departamentos e que um usuário tenha múltiplos vínculos simultâneos.

```text
User ──< user_department >── Department
User ──< user_job_function >── JobFunction
Department ──< department_job_function >── JobFunction
```

### Tabelas principais

#### `users`

- `id`
- `workos_user_id` nullable/unique durante provisionamento
- `name`
- `email`
- `employee_id` nullable/unique por organização
- `status` (`invited`, `active`, `suspended`, `terminated`)
- `hired_at` nullable
- `terminated_at` nullable
- timestamps

#### `departments`

- `id`, `name`, `code`, `status`, timestamps

#### `job_functions`

- `id`, `name`, `code`, `status`, timestamps

#### Pivôs

- `department_job_function`
- `user_department`
- `user_job_function`

Os vínculos devem manter datas efetivas (`starts_at`, `ends_at`) quando necessário. Isso permite reconstruir por que uma pessoa pertencia ao público de uma regra em determinada data.

---

## 6. Cursos, versões e lessons

### Estrutura

```text
Course
  └── CourseVersion
        ├── Lesson 1
        │     ├── Video
        │     └── Questions
        └── Lesson N
              ├── Video
              └── Questions
```

### `courses`

Representa a identidade permanente do curso:

- `id`
- `code`
- `title`
- `description`
- `status`
- `current_published_version_id` nullable
- timestamps

### `course_versions`

Representa uma edição específica e auditável:

- `id`
- `course_id`
- `version_number`
- `status` (`draft`, `published`, `retired`)
- `title`, `description`
- `completion_rule`
- `published_at`, `published_by`
- timestamps

Uma versão em rascunho pode ser alterada. Depois de publicada e utilizada, torna-se imutável. Qualquer mudança em vídeo, questão, alternativa, resposta correta, threshold ou ordem de lesson exige nova versão.

Assignments e certificados sempre apontam para um `course_version_id` específico.

### `lessons`

- `id`
- `course_version_id`
- `title`
- `description` nullable
- `type` (inicialmente `video`)
- `position`
- `is_required`
- `minimum_watch_percentage` (por exemplo, 90 ou 95)
- `passing_score`
- timestamps

O MVD conclui o curso quando todas as lessons obrigatórias forem concluídas. Não é necessário um segundo threshold agregado do curso se cada lesson já possui sua própria regra de aprovação.

### `videos`

- `id`
- `lesson_id`
- `provider`
- `provider_asset_id`
- `provider_playback_id` nullable
- `duration_seconds` nullable
- `status` (`uploading`, `processing`, `ready`, `failed`)
- `metadata` JSON nullable
- timestamps

Nenhuma URL pública permanente deve ser persistida como mecanismo de autorização.

---

## 7. Questões, respostas e tentativas

### `questions`

- `id`
- `lesson_id`
- `type` (`single_choice`, `multiple_choice`)
- `prompt`
- `position`
- `max_attempts`
- `weight` default 1
- timestamps

### `question_options`

- `id`
- `question_id`
- `text`
- `is_correct`
- `position`
- timestamps

### Regras de execução

- a avaliação é liberada somente após o vídeo atingir o critério de visualização;
- cada pergunta respeita seu número máximo de tentativas;
- respostas incorretas permanecem no histórico;
- ao esgotar tentativas, a `lesson_attempt` falha;
- a política inicial pode exigir reassistir a lesson antes de uma nova tentativa;
- uma nova execução cria novos registros, nunca apaga nem reinicia os anteriores.

### Tabelas de tentativa

#### `course_attempts`

- `id`, `assignment_id`, `course_version_id`
- `attempt_number`
- `status`, `started_at`, `completed_at`
- `score` nullable

#### `lesson_attempts`

- `id`, `course_attempt_id`, `lesson_id`
- `attempt_number`
- `status`, `started_at`, `completed_at`
- `score` nullable

#### `question_attempts`

- `id`, `lesson_attempt_id`, `question_id`
- `attempt_number`
- `selected_option_ids` JSON ou relação normalizada
- `is_correct`
- `answered_at`

#### `lesson_progress`

Tabela derivada/operacional para leitura rápida:

- `assignment_id`, `lesson_id`
- `started_at`
- `last_position_seconds`
- `watched_seconds`
- `percentage_watched`
- `completed_at`
- `updated_at`

Ela não substitui os eventos append-only.

---

## 8. Training Requirements

`TrainingRequirement` declara quem deve possuir qual treinamento e com qual periodicidade. Ele não representa, por si só, a obrigação individual de um funcionário.

### `training_requirements`

- `id`
- `course_id`
- `name`
- `status` (`draft`, `active`, `paused`, `retired`)
- `frequency_type` (`once`, `days`, `months`, `years`)
- `frequency_value` nullable para `once`
- `renewal_basis` (`from_completion`, `from_due_date`)
- `assignment_lead_days` ou política equivalente
- `due_days_after_assignment`
- `effective_from`, `effective_until` nullable
- `created_by`, timestamps

### Público-alvo

O MVD deve suportar:

- todos os funcionários;
- todo um departamento;
- uma função;
- departamento + função;
- múltiplos targets simples dentro do mesmo requirement.

Exemplo:

```text
Curso: Segurança Offshore

Target A: Operações + Supervisor → a cada 6 meses
Target B: Manutenção + Soldador → a cada 12 meses
```

Como a recorrência pode variar por função ou setor, cada combinação com periodicidade distinta deve ser um requirement próprio, ainda que aponte para o mesmo curso.

### `training_requirement_targets`

- `id`
- `training_requirement_id`
- `scope_type` (`everyone`, `department`, `job_function`, `department_job_function`)
- `department_id` nullable
- `job_function_id` nullable
- timestamps

O MVD não precisa de uma linguagem booleana arbitrária. Targets explícitos cobrem o caso inicial e são mais auditáveis.

---

## 9. Assignments individuais

`user_training_assignments` é a obrigação materializada e histórica de uma pessoa realizar uma versão de curso.

### Origens suportadas

- `requirement`: gerado por regra ativa;
- `manual`: criado diretamente por um administrador;
- `mobilization`: futuro, criado a partir de agenda de mobilização;
- `job`: futuro, criado por importação ou integração operacional;
- `api/import`: reservado para outras integrações.

### `user_training_assignments`

- `id`
- `user_id`
- `course_id`
- `course_version_id`
- `training_requirement_id` nullable
- `origin_type`
- `origin_id` nullable
- `series_key` nullable
- `assigned_at`
- `available_at` nullable
- `due_at` nullable
- `expires_at` nullable
- `status` (`pending`, `in_progress`, `completed`, `failed`, `overdue`, `cancelled`, `waived`)
- `completed_at` nullable
- `supersedes_assignment_id` nullable
- `metadata` JSON nullable
- timestamps

### Decisões importantes

- Um assignment pode existir sem requirement.
- O assignment congela a `course_version_id` aplicável.
- Mudanças de setor/função não apagam uma obrigação já materializada.
- Não se criam antecipadamente todas as ocorrências futuras de uma recorrência.
- A engine cria a ocorrência necessária e agenda/materializa a próxima no momento apropriado.
- Alterações em uma regra não reescrevem o histórico; podem encerrar uma série e iniciar outra.
- `series_key` permite relacionar ocorrências recorrentes sem depender da geração antecipada.

### Mobilization/job no futuro

Uma importação futura poderá criar um assignment avulso com `available_at` e `due_at` alinhados à data/hora de embarque ou início do job. Isso não exige redesenhar requirements nem courses.

---

## 10. Engine de materialização e recorrência

Um job idempotente deve:

1. buscar requirements ativos;
2. resolver usuários elegíveis pelos targets e vínculos efetivos;
3. verificar se já existe assignment equivalente para o ciclo;
4. criar somente os assignments ausentes;
5. registrar o motivo e a regra que originaram cada assignment;
6. agendar notificações e próximas avaliações.

Recomenda-se uma chave única lógica por `user + requirement + cycle`, evitando duplicação mesmo se o job for executado novamente.

Para recorrência:

- `from_completion`: o próximo ciclo parte da conclusão anterior;
- `from_due_date`: o próximo ciclo preserva o calendário originalmente previsto.

O MVD pode iniciar com `from_completion`, mantendo ambos os valores no modelo.

---

## 11. Compliance events append-only

Toda interação relevante deve produzir um evento imutável. As tabelas de progresso representam o estado atual; `compliance_events` preserva a sequência factual.

### `compliance_events`

- `id` interno ordenável
- `uuid` gerado no cliente, globalmente único
- `event_type`
- `user_id`
- `assignment_id` nullable quando ainda não aplicável
- `course_version_id` nullable
- `lesson_id` nullable
- `course_attempt_id` nullable
- `lesson_attempt_id` nullable
- `question_id` nullable
- `device_id` nullable
- `session_id`
- `occurred_at`
- `received_at`
- `client_sequence` nullable
- `position_seconds` nullable
- `metadata` JSON nullable
- `ip_address` nullable
- `user_agent` nullable
- `created_at`

`uuid` deve possuir índice unique. Uma repetição de sincronização com o mesmo UUID retorna sucesso sem inserir um segundo evento, garantindo idempotência.

### Eventos iniciais sugeridos

```text
assignment.created
assignment.opened
course.started
lesson.started
video.played
video.paused
video.seeked
video.progressed
video.ended
video.rewatched
question.presented
question.answered
question.failed
question.passed
lesson.failed
lesson.completed
course.failed
course.completed
certificate.issued
notification.queued
notification.sent
notification.failed
```

Eventos de vídeo muito frequentes devem ser agregados em intervalos razoáveis para evitar ruído, mantendo play, pause, seek, ended e checkpoints suficientes para reconstruir a sessão.

### `occurred_at` versus `received_at`

- `occurred_at`: instante alegado pelo dispositivo em que a ação aconteceu;
- `received_at`: instante confiável em que o servidor recebeu o evento.

Essa distinção é essencial para o futuro modo offline. Regras de validação devem tratar relógios incorretos, eventos fora de ordem e conflitos sem alterar os registros originais.

### Audit log administrativo

Além dos eventos de execução, recomenda-se `audit_logs` para mudanças administrativas:

- ator;
- ação;
- tipo e ID do objeto;
- antes/depois quando apropriado;
- IP e timestamp;
- metadados.

Exemplos: publicar versão, alterar vínculo organizacional, criar requirement, cancelar assignment e emitir waiver.

---

## 12. Vídeo privado e decisão de provedor

### Recomendação para o MVD: Cloudflare Stream

Cloudflare Stream deve ser o provedor inicial porque reúne ingestão, encoding, streaming adaptativo, CDN, player/API e autorização por signed URL/token. O modelo desejado é controlado pelo backend:

```text
Usuário autenticado
    ↓
Laravel valida usuário, assignment, versão e disponibilidade
    ↓
Laravel solicita/gera token temporário
    ↓
Player recebe playback autorizado do Cloudflare Stream
```

Fluxo sugerido:

```http
POST /assignments/{assignment}/lessons/{lesson}/playback
```

O endpoint:

1. autentica o usuário;
2. aplica a policy;
3. confirma que o assignment pertence ao usuário;
4. confirma versão, janela de disponibilidade e status;
5. emite token curto, idealmente 15–30 minutos, renovável enquanto a sessão continuar válida;
6. registra o evento de autorização.

Copiar a URL não deve conceder acesso permanente. O token expira e o backend permanece como autoridade.

### `VideoProvider` abstraction

O domínio não deve depender diretamente de Cloudflare:

```php
interface VideoProvider
{
    public function createUpload(...): VideoUpload;
    public function getAssetStatus(...): VideoAssetStatus;
    public function createPlaybackAuthorization(...): PlaybackAuthorization;
    public function createDownloadAuthorization(...): DownloadAuthorization;
    public function deleteAsset(...): void;
}
```

Implementação inicial:

```text
CloudflareStreamProvider
```

Tokens, chaves e detalhes de URL ficam isolados na infraestrutura. Lessons e assignments guardam somente identificadores estáveis do asset/provedor.

### Alternativas

| Opção | Posição | Observação |
|---|---|---|
| Cloudflare Stream | Recomendada no MVD | Boa relação entre simplicidade, custo e autorização temporária |
| Mux | Alternativa forte | Excelente para signed playback e evolução para DRM |
| Vimeo | Alternativa aceitável | Familiar, privacidade e restrição de embed; autorização tende a ser menos centrada no assignment |
| YouTube | Não recomendado | Privacidade/unlisted não atende bem ao modelo corporativo controlado pelo backend |
| S3/R2 com streaming próprio | Não recomendado | Recriaria encoding, player, distribuição e proteção já oferecidos por provedores especializados |

Preços e capacidades comerciais devem ser reconfirmados no momento da contratação.

### Limite real de segurança

Nenhum provedor impede absolutamente a cópia de conteúdo que pode ser exibido. O objetivo é impedir acesso não autorizado, reduzir extração casual e tornar eventual vazamento rastreável. Watermark dinâmico e DRM podem ser avaliados posteriormente se o risco justificar.

---

## 13. Preparação para iPad e offline

O offline não será implementado no MVD, mas quatro decisões devem existir desde a primeira migration:

1. eventos possuem UUID gerado no cliente;
2. eventos distinguem `occurred_at` e `received_at`;
3. eventos aceitam `device_id` e `session_id`;
4. a API de ingestão é idempotente e aceita lotes.

### Fluxo futuro

```text
iPad autenticado e autorizado
    ↓
Assignments e conteúdo elegível são preparados
    ↓
Vídeo é baixado para o sandbox do app
    ↓
Interações são registradas em fila local append-only
    ↓
Conectividade retorna
    ↓
App envia eventos em lotes com UUID
    ↓
Servidor ignora duplicatas e reconstrói o estado
```

### Segurança futura do download

- URL de download temporária e vinculada à autorização;
- armazenamento no sandbox do aplicativo;
- criptografia local e proteção por chaves do dispositivo;
- validade e revogação do pacote;
- remoção automática após conclusão ou expiração;
- integridade do manifesto e do arquivo;
- política de dispositivo compartilhado e logout seguro.

Download protegido não equivale a DRM offline. Se DRM certificado virar requisito, a abstração de vídeo permitirá avaliar Mux ou outro provedor sem alterar o domínio de treinamento.

---

## 14. Course editor em tela única

O administrativo deve editar um curso em uma única tela Livewire, evitando um fluxo fragmentado em muitas páginas.

### Estrutura da tela

```text
[Course details]
  título, código, descrição

[Version settings]
  status, regra de conclusão

[Lesson 1 — expansível]
  título
  vídeo/upload/status
  critério de visualização
  passing score
  perguntas
    - enunciado
    - tipo
    - alternativas
    - respostas corretas
    - máximo de tentativas

[Lesson 2 — expansível]
  ...

[+ Add Lesson]

[Save Draft] [Preview] [Publish Version]
```

### Regras de UX

- autosave ou indicação clara de alterações não salvas;
- drag-and-drop para ordenar lessons, questões e alternativas;
- validação inline;
- status de processamento do vídeo;
- preview como funcionário;
- confirmação forte ao publicar, explicando a imutabilidade;
- edição posterior cria uma nova versão em rascunho.

---

## 15. Dashboards e relatórios

### Dashboard administrativo

Cards sugeridos:

- total de funcionários;
- compliant;
- due soon;
- overdue;
- critical overdue;
- taxa de conclusão por período.

Tabela operacional:

| Employee | Department | Function | Course | Due date | Status | Days overdue |
|---|---|---|---|---|---|---:|

Filtros:

- departamento;
- função;
- curso;
- status;
- faixa de vencimento;
- período;
- origem do assignment.

Faixas úteis: due soon, 1–7 dias, 8–30, 31–60 e 60+ dias em atraso.

Drill-down deve abrir a linha do tempo do assignment, incluindo versão, tentativas, eventos relevantes, notificações e certificado.

### Dashboard do funcionário

A primeira tela prioriza ação:

- treinamentos vencidos;
- treinamentos próximos do vencimento;
- treinamentos em andamento;
- concluídos e certificados.

Cada card mostra curso, prazo, duração estimada, progresso e ação `Start` ou `Continue`. Catálogo livre, se existir, é secundário ao compliance obrigatório.

### Cálculo de compliance

O status agregado deve ser derivado de assignments materializados e suas datas, não calculado apenas pela associação atual do usuário ao setor/função.

---

## 16. Notificações

Notificações devem ser geradas por filas e registradas para auditoria.

### Gatilhos iniciais

- assignment criado;
- prazo se aproximando;
- atraso iniciado;
- lembretes periódicos de atraso;
- treinamento concluído;
- certificado emitido;
- falha operacional que exige ação do administrador.

### Canais

- e-mail no MVD;
- notificação in-app;
- canais futuros via integrações corporativas.

Uma política configurável define antecedência e frequência, evitando duplicidade com uma chave idempotente por `assignment + notification_type + scheduled_date`.

---

## 17. Certificados

Ao concluir um curso, um job em fila gera o certificado e registra sua emissão.

### `certificates`

- `id`
- `certificate_number` unique
- `verification_code` unique
- `user_id`
- `assignment_id`
- `course_id`
- `course_version_id`
- `issued_at`
- `expires_at` nullable
- `score` nullable
- `file_path`
- `revoked_at` nullable
- `revocation_reason` nullable
- timestamps

O PDF contém nome, curso, versão quando apropriado, data de conclusão, validade, número e QR code.

A página pública de verificação deve expor apenas o mínimo necessário: validade, nome, curso, emissão e expiração. Ela não deve revelar employee ID, respostas, departamento ou histórico completo.

---

## 18. LGPD e governança de dados

A LGPD se aplica porque a plataforma trata dados pessoais ligados a pessoas identificadas: nome, e-mail, identificador funcional, setor, função, histórico, avaliações e certificados.

Em uma configuração típica, a Oceanix tende a atuar como controladora, a empresa operadora da plataforma como operadora e WorkOS, Cloudflare, e-mail e infraestrutura como suboperadores, sujeito aos contratos e às decisões efetivas sobre finalidade e meios.

### Requisitos desde o início

- finalidade documentada para cada categoria de dado;
- minimização: coletar somente o necessário;
- controle de acesso por papel e escopo;
- criptografia em trânsito e em repouso;
- segregação de ambientes e segredos;
- trilha de auditoria;
- política de retenção por categoria;
- backups protegidos e testados;
- procedimento para incidentes;
- processo para solicitações de titulares;
- contratos e avaliação dos suboperadores;
- análise de transferência internacional;
- política para desligamento, bloqueio, anonimização ou exclusão;
- documentação da base legal adequada, sem presumir que consentimento é sempre necessário.

### Retenção versus obrigação de compliance

Exclusão não deve destruir automaticamente evidências cuja retenção seja necessária por obrigação legal, regulatória ou defesa de direitos. A política deve separar:

- dados de conta operacional;
- evidência de treinamento;
- logs técnicos;
- certificados;
- backups.

Quando possível, informações não mais necessárias devem ser excluídas ou anonimizadas. A definição final de base legal e prazos requer validação jurídica brasileira.

---

## 19. Modelo de dados consolidado

```text
IDENTITY / ORGANIZATION
users
departments
job_functions
department_job_function
user_department
user_job_function

CONTENT
courses
course_versions
lessons
videos
questions
question_options

REQUIREMENTS
training_requirements
training_requirement_targets

ASSIGNMENTS / EXECUTION
user_training_assignments
course_attempts
lesson_attempts
question_attempts
lesson_progress

COMPLIANCE / OPERATIONS
compliance_events
audit_logs
certificates
notifications
notification_deliveries
devices (futuro)
offline_content_packages (futuro)
sync_batches (futuro)
```

### Relações essenciais

```text
Course 1 ── N CourseVersion
CourseVersion 1 ── N Lesson
Lesson 1 ── 1 Video
Lesson 1 ── N Question
Question 1 ── N QuestionOption

TrainingRequirement N ── 1 Course
TrainingRequirement 1 ── N Target
TrainingRequirement 1 ── N Assignment

User 1 ── N Assignment
Assignment N ── 1 CourseVersion
Assignment 1 ── N CourseAttempt
CourseAttempt 1 ── N LessonAttempt
LessonAttempt 1 ── N QuestionAttempt

Assignment 1 ── N ComplianceEvent
Assignment 1 ── 0..1 Certificate
```

---

## 20. Fluxos principais

### Publicação de curso

```text
Admin cria Course
    ↓
Edita CourseVersion draft em tela única
    ↓
Upload e processamento dos vídeos
    ↓
Preview e validações
    ↓
Publica versão
    ↓
Versão se torna imutável
```

### Requirement até assignment

```text
Admin cria requirement e targets
    ↓
Engine resolve usuários elegíveis
    ↓
Cria assignments idempotentemente
    ↓
Agenda notificações
    ↓
Mantém vínculo histórico com a regra
```

### Execução pelo funcionário

```text
Employee abre assignment
    ↓
Laravel autoriza e emite token temporário de vídeo
    ↓
Player registra eventos e checkpoints
    ↓
Critério de visualização é alcançado
    ↓
Avaliação é liberada
    ↓
Passed → próxima lesson
Failed → registra tentativa e aplica reassistência
    ↓
Todas as lessons concluídas
    ↓
Assignment completed + certificado + notificações
```

### Renovação

```text
Conclusão ou due date, conforme renewal_basis
    ↓
Calcula próximo ciclo
    ↓
Materializa somente a próxima ocorrência no momento correto
    ↓
Preserva todas as ocorrências anteriores
```

---

## 21. Jobs e serviços de domínio

Serviços sugeridos:

- `RequirementEligibilityService`
- `AssignmentMaterializationService`
- `RecurrenceService`
- `TrainingCompletionService`
- `ComplianceEventIngestionService`
- `ComplianceProjectionService`
- `PlaybackAuthorizationService`
- `CertificateService`
- `NotificationSchedulingService`

Jobs sugeridos:

- materializar requirements ativos;
- atualizar status overdue;
- criar a próxima ocorrência recorrente;
- projetar progresso a partir de eventos;
- enviar lembretes;
- gerar certificado;
- reconciliar assets de vídeo;
- processar lotes de sync no futuro.

Todos os jobs devem ser idempotentes e seguros para retry.

---

## 22. Segurança

- Policies Laravel em todo acesso a assignment, curso e relatório;
- tokens de vídeo curtos e renováveis;
- secrets apenas no ambiente seguro;
- rate limiting em playback, login e ingestão de eventos;
- validação de assinatura/webhooks dos provedores;
- proteção CSRF e sessão segura;
- logs sem tokens, respostas sensíveis ou secrets;
- princípio do menor privilégio;
- trilha de alterações administrativas;
- revogação de sessões e acesso ao desligar usuários;
- monitoramento de padrões anômalos de playback e sync.

Eventos enviados pelo cliente são evidência, mas não devem ser aceitos cegamente. O servidor valida ownership, sequência, duração plausível, versão e janela do assignment.

---

## 23. Critérios de aceite do MVD

O MVD estará funcionalmente completo quando:

1. um usuário entrar via WorkOS e tiver seus vínculos locais;
2. um administrador criar e publicar uma versão imutável de curso em tela única;
3. lessons contiverem vídeo privado, questões e regras de aprovação;
4. um requirement selecionar usuários por setor e/ou função;
5. a engine criar assignments sem duplicidade;
6. um admin também criar assignment manual sem requirement;
7. o funcionário reproduzir vídeo somente após autorização temporária;
8. play, pause, seek, progresso, respostas, tentativas e conclusão gerarem eventos append-only;
9. falhas e reassistências preservarem o histórico;
10. conclusão gerar certificado verificável;
11. dashboards mostrarem pendências e conformidade com filtros;
12. notificações de atribuição, vencimento e conclusão forem registradas;
13. o esquema de eventos já suportar UUID, device/session e timestamps duplos;
14. nenhuma funcionalidade offline estiver exposta como pronta antes de receber implementação e validação próprias.

---

## 24. Roadmap sugerido

### Fase 1 — Fundação

- Laravel/Livewire, WorkOS, autorização;
- usuários, departamentos e funções N:N;
- cursos, versões e course editor;
- integração Cloudflare Stream via `VideoProvider`.

### Fase 2 — Execução e compliance

- requirements e materialização;
- assignments manuais;
- player, progresso, avaliações e tentativas;
- eventos append-only e projeções.

### Fase 3 — Operação

- dashboards;
- notificações;
- certificados;
- auditoria e rotinas LGPD;
- relatórios e exportação.

### Fase futura — Mobilização e offline

- importação de schedule/job;
- assignments com prazo pré-embarque;
- app iPad e gestão de dispositivos;
- pacotes offline e downloads temporários;
- sync em lotes idempotente;
- reconciliação e conflitos;
- DRM/watermark se o risco exigir.

---

## 25. Decisões finais

- **Framework:** Laravel + Livewire.
- **Identidade:** WorkOS, desacoplado do domínio de treinamento.
- **Organização:** departamentos e funções N:N.
- **Conteúdo:** Course → CourseVersion → Lesson → Video/Questions.
- **Imutabilidade:** versão publicada nunca é editada retroativamente.
- **Compliance:** requirement gera assignment, mas assignment também pode ser avulso.
- **Recorrência:** definida por requirement e, portanto, pode variar por função/setor.
- **Histórico:** tentativas e `compliance_events` são preservados.
- **Offline:** não entra no MVD, mas UUID, device/session e timestamps duplos entram agora.
- **Vídeo no MVD:** Cloudflare Stream com signed URLs/tokens de curta duração.
- **Portabilidade:** acesso ao vídeo por `VideoProvider`; Mux e Vimeo permanecem alternativas.
- **LGPD:** requisito arquitetural e operacional, não apenas uma tela de consentimento.

O resultado é uma base preparada para evoluir de uma aplicação web de treinamento para uma plataforma operacional de compliance offshore, sem antecipar a complexidade do aplicativo offline antes de ela ser necessária.
