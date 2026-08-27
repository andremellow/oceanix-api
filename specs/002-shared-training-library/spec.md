# Especificação da Feature: Biblioteca Compartilhada de Treinamentos

**Feature Branch**: `codex/video-library`

**Created**: 2026-08-26

**Status**: Draft

**Input**: Permitir que administradores da plataforma criem e gerenciem cursos e módulos compartilhados, que empresas possam associar à sua biblioteca sem copiá-los, que cursos próprios combinem módulos próprios e compartilhados e que um curso de empresa possa ser promovido para compartilhado.

## Clarifications

### Session 2026-08-26

- Q: Ao promover um curso, o que acontece com módulos próprios também usados por outros cursos da empresa? → A: Todos esses módulos também se tornam compartilhados; os outros cursos continuam usando-os sob gestão da plataforma.
- Q: Quem deve receber automaticamente uma nova versão compartilhada? → A: Todos que ainda não iniciaram migram automaticamente; quem está em progresso permanece na versão anterior por padrão, mas a plataforma pode optar por reiniciá-los na nova versão durante a publicação; conclusões permanecem intactas.
- Q: Como uma nova versão de módulo compartilhado afeta os cursos que o utilizam? → A: O sistema cria e publica automaticamente novas versões de todos os cursos afetados e aplica a regra definida de migração de assignments.
- Q: O que acontece quando um curso afetado pela atualização automática já possui um rascunho? → A: A versão automática parte da última versão publicada e o rascunho existente permanece separado e intacto.
- Q: Empresas já associadas podem criar novos assignments após o arquivamento de um curso compartilhado? → A: Não; novos assignments são bloqueados, enquanto assignments existentes continuam normalmente.

## User Scenarios & Testing

### User Story 1 - Administrar conteúdo compartilhado na plataforma (Priority: P1)

Como superadministrador, quero criar, editar, publicar e arquivar cursos e módulos compartilhados na área da plataforma para manter uma biblioteca central reutilizável por várias empresas.

**Why this priority**: Sem conteúdo pertencente à plataforma e um local exclusivo de administração, nenhuma das jornadas de compartilhamento é possível com ownership claro.

**Independent Test**: Um superadministrador cria e publica um curso compartilhado em `/platform`, enquanto um administrador de empresa consegue visualizá-lo no catálogo, mas não editar seu conteúdo.

**Acceptance Scenarios**:

1. **Given** um superadministrador autenticado na área da plataforma, **When** cria um curso ou módulo compartilhado, **Then** o conteúdo pertence à plataforma e só pode ser administrado nessa área.
2. **Given** um curso compartilhado publicado, **When** um administrador de empresa o encontra no catálogo, **Then** vê seus detalhes e sua origem compartilhada sem receber controles de edição do conteúdo.
3. **Given** um administrador de empresa, **When** tenta acessar diretamente uma ação de criação ou edição de conteúdo compartilhado, **Then** a ação é negada e nenhum conteúdo é alterado.
4. **Given** uma versão compartilhada já publicada, **When** o superadministrador precisa alterar seu conteúdo, **Then** a alteração ocorre em uma nova versão em rascunho e a versão publicada permanece imutável.
5. **Given** uma nova versão pronta para publicação, **When** o superadministrador revisa o impacto, **Then** vê a quantidade de assignments não iniciados e em progresso e escolhe se os que estão em progresso também devem reiniciar na nova versão.
6. **Given** a publicação de uma nova versão, **When** existem assignments ainda não iniciados, **Then** eles são substituídos automaticamente por obrigações equivalentes na nova versão.
7. **Given** a publicação sem a opção de reiniciar quem está em progresso, **When** existem assignments iniciados, **Then** eles permanecem vinculados à versão anterior.
8. **Given** a publicação com a opção de reiniciar quem está em progresso, **When** existem assignments iniciados, **Then** as obrigações anteriores são canceladas com motivo registrado e novas obrigações equivalentes são criadas na nova versão, sem transferir o progresso anterior.

---

### User Story 2 - Adicionar um curso compartilhado a uma empresa (Priority: P1)

Como administrador de empresa, quero navegar pela biblioteca compartilhada e adicionar um curso à minha empresa para disponibilizá-lo sem criar uma cópia ou assumir sua manutenção.

**Why this priority**: Esta jornada entrega o principal valor de negócio: reutilizar um único curso mantido centralmente entre diferentes empresas.

**Independent Test**: Um administrador adiciona um curso compartilhado à empresa, vê o mesmo curso em sua biblioteca com identificação de conteúdo compartilhado e pode removê-lo quando não houver obrigações que impeçam a operação.

**Acceptance Scenarios**:

1. **Given** um curso compartilhado publicado e ainda não associado à empresa, **When** o administrador seleciona `Add to Company`, **Then** o curso passa a aparecer na biblioteca da empresa sem duplicação de conteúdo.
2. **Given** um curso compartilhado já associado, **When** qualquer usuário autorizado o visualiza na empresa, **Then** a interface o identifica como `Shared` e informa que ele é gerenciado pela plataforma.
3. **Given** um curso compartilhado já associado à empresa, **When** o administrador tenta adicioná-lo novamente, **Then** o sistema mantém uma única associação e informa que o curso já está disponível.
4. **Given** um curso compartilhado que não possui obrigações operacionais impeditivas na empresa, **When** o administrador o remove da biblioteca, **Then** novas utilizações deixam de ser permitidas nessa empresa sem apagar históricos existentes.

---

### User Story 3 - Reutilizar módulo compartilhado em curso da empresa (Priority: P1)

Como administrador de empresa, quero combinar módulos próprios e compartilhados em um curso próprio para reutilizar treinamento padronizado sem perder o conteúdo específico da empresa.

**Why this priority**: O módulo é a unidade de reutilização que permite compor treinamentos adaptados sem duplicar conteúdo mantido pela plataforma.

**Independent Test**: Um administrador cria um curso da empresa, inclui ao menos um módulo próprio e um compartilhado, publica o curso e confirma que ambos fazem parte da experiência do aluno enquanto apenas o módulo próprio pode ser editado pela empresa.

**Acceptance Scenarios**:

1. **Given** um curso da empresa em rascunho, **When** o administrador navega pelos módulos compartilhados disponíveis e adiciona um deles, **Then** o módulo entra na composição do curso sem mudar de ownership.
2. **Given** um curso da empresa contendo módulos próprios e compartilhados, **When** o administrador o edita, **Then** pode ordenar e remover módulos da composição, mas só pode alterar o conteúdo pertencente à empresa.
3. **Given** um curso da empresa pronto para publicação, **When** ele contém um módulo compartilhado indisponível, arquivado ou sem versão publicável, **Then** a publicação é impedida com uma explicação acionável.
4. **Given** um curso da empresa publicado com um módulo compartilhado, **When** a plataforma publica uma nova versão desse módulo, **Then** o sistema preserva a versão anterior e cria e publica automaticamente uma nova versão do curso usando a nova versão do módulo.
5. **Given** vários cursos compartilhados ou de empresas usando o mesmo módulo compartilhado, **When** sua nova versão é publicada, **Then** cada curso recebe automaticamente uma nova versão publicada e seus assignments são tratados conforme as regras de migração por estado.
6. **Given** um curso afetado que já possui uma versão em rascunho, **When** a nova versão do módulo compartilhado é publicada, **Then** a versão automática do curso parte da última versão publicada e o rascunho existente permanece intacto e não publicado.

---

### User Story 4 - Promover curso da empresa para compartilhado (Priority: P2)

Como superadministrador, quero promover um curso pertencente a uma empresa para a biblioteca compartilhada para reutilizá-lo em outras empresas e centralizar sua manutenção.

**Why this priority**: A promoção aproveita conteúdo de alta qualidade já produzido, mas depende das fronteiras de ownership e associação das jornadas anteriores.

**Independent Test**: Um superadministrador promove um curso da empresa, confirma a transferência, verifica que a empresa de origem mantém acesso e que somente a plataforma consegue editar versões futuras.

**Acceptance Scenarios**:

1. **Given** um curso pertencente a uma empresa e elegível para promoção, **When** o superadministrador confirma `Make Shared`, **Then** o ownership passa para a plataforma e a empresa de origem mantém uma associação ao curso.
2. **Given** a confirmação da promoção, **When** ela é apresentada ao superadministrador, **Then** identifica a empresa de origem, explica a perda de edição pela empresa e informa que o conteúdo será administrado pela plataforma.
3. **Given** um curso promovido, **When** um administrador da empresa de origem o abre, **Then** consegue utilizá-lo conforme suas permissões, mas não consegue editar seu conteúdo.
4. **Given** um curso promovido que já possui assignments e certificados, **When** a promoção é concluída, **Then** todas as referências históricas continuam válidas e apontam para as mesmas versões.
5. **Given** um módulo próprio do curso promovido também utilizado por outros cursos da empresa, **When** a promoção é confirmada, **Then** o módulo também se torna compartilhado e os demais cursos continuam referenciando-o, agora sob gestão da plataforma.

---

### User Story 5 - Controlar disponibilidade e ciclo de vida (Priority: P2)

Como superadministrador, quero controlar quais conteúdos compartilhados podem ser descobertos ou adicionados por empresas para interromper novas adoções sem apagar evidências ou prejudicar usos existentes.

**Why this priority**: Conteúdo compartilhado exige retirada segura e previsível, especialmente quando já sustenta obrigações de compliance em vários tenants.

**Independent Test**: Um superadministrador arquiva conteúdo associado a empresas e confirma que ele desaparece para novas adoções, enquanto versões, assignments, conclusões e certificados existentes continuam acessíveis segundo suas regras históricas.

**Acceptance Scenarios**:

1. **Given** um conteúdo compartilhado publicado e disponível, **When** ele é arquivado, **Then** deixa de aparecer para novas associações e novas composições.
2. **Given** conteúdo compartilhado arquivado que já participa de cursos ou assignments, **When** usuários consultam históricos, **Then** as evidências e versões utilizadas permanecem preservadas.
3. **Given** um curso compartilhado associado a várias empresas, **When** uma empresa o remove de sua biblioteca, **Then** as associações das demais empresas não são afetadas.
4. **Given** um curso compartilhado arquivado, **When** uma empresa já associada tenta criar um novo assignment, **Then** a operação é bloqueada com uma explicação clara.
5. **Given** um curso compartilhado arquivado com assignments existentes, **When** as pessoas continuam seus treinamentos, **Then** esses assignments permanecem disponíveis e seguem normalmente até seu encerramento.

### Edge Cases

- Dois administradores tentam associar o mesmo curso compartilhado à mesma empresa ao mesmo tempo.
- Um conteúdo compartilhado é arquivado enquanto está aberto no catálogo de uma empresa ou sendo adicionado a um curso em rascunho.
- Um módulo compartilhado recebe uma nova versão enquanto também é usado por cursos publicados e rascunhos de várias empresas.
- Um curso da empresa contém módulos pertencentes a empresas diferentes por dados legados ou tentativa de acesso direto.
- A promoção é solicitada para um curso inexistente, arquivado, já compartilhado ou pertencente a outra empresa que não a exibida na confirmação.
- A promoção inclui conteúdo próprio da empresa que não pode ser compartilhado com segurança; a operação deve apontar os impedimentos antes de qualquer mudança de ownership.
- A empresa de origem está suspensa após a promoção de um curso; o conteúdo compartilhado continua pertencendo à plataforma e as evidências da empresa permanecem preservadas.
- Um curso ou módulo compartilhado muda de título ou código e precisa continuar reconhecível em históricos e associações existentes.
- Uma empresa tenta remover um curso compartilhado que sustenta requirements, assignments abertos ou outras obrigações ativas.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST distinguir explicitamente conteúdo pertencente à plataforma de conteúdo pertencente a uma empresa, tanto para cursos quanto para módulos.
- **FR-002**: O sistema MUST tratar ownership e disponibilidade como conceitos independentes: pertencer à plataforma não torna um conteúdo automaticamente disponível em todas as empresas.
- **FR-003**: Somente superadministradores MUST poder criar, editar, publicar, arquivar ou administrar conteúdos pertencentes à plataforma.
- **FR-004**: Administradores de empresa MUST poder criar e administrar conteúdos pertencentes apenas à sua empresa, conforme permissões atômicas específicas.
- **FR-005**: A área da plataforma MUST oferecer diretórios distintos de cursos compartilhados e módulos compartilhados, com busca, estado de publicação e ações de ciclo de vida.
- **FR-006**: Uma empresa MUST poder navegar por cursos compartilhados publicados e disponíveis, pesquisar o catálogo, consultar detalhes e associar um curso à sua biblioteca.
- **FR-007**: A associação de um curso compartilhado a uma empresa MUST referenciar o conteúdo mantido pela plataforma e MUST NOT criar uma cópia do curso ou transferir seu ownership.
- **FR-008**: A mesma combinação de empresa e curso compartilhado MUST possuir no máximo uma associação ativa.
- **FR-009**: Conteúdo compartilhado exibido dentro de uma empresa MUST ser identificado visualmente como `Shared` e MUST informar que sua edição é feita pela administração da plataforma.
- **FR-010**: O sistema MUST negar, inclusive em acesso direto, qualquer tentativa de uma empresa alterar o conteúdo ou o ciclo de vida de um curso ou módulo compartilhado.
- **FR-011**: Um curso da empresa em rascunho MUST poder combinar módulos próprios daquela empresa com módulos compartilhados publicados e disponíveis.
- **FR-012**: Um administrador de empresa MUST poder adicionar, remover e ordenar módulos na composição de um curso próprio sem alterar o ownership dos módulos compartilhados.
- **FR-013**: Nenhum curso da empresa MUST poder referenciar módulo pertencente a outra empresa.
- **FR-014**: Uma versão publicada de curso ou módulo MUST permanecer imutável; qualquer alteração de conteúdo MUST produzir uma nova versão em rascunho.
- **FR-015**: A publicação de uma nova versão compartilhada MUST NOT reescrever versões de cursos já publicados, assignments, tentativas, evidências ou certificados existentes; qualquer migração MUST cancelar a obrigação anterior com motivo e criar uma obrigação equivalente ligada à nova versão.
- **FR-016**: Ao publicar uma nova versão compartilhada, o sistema MUST substituir automaticamente todos os assignments ainda não iniciados pela nova versão, preservando público, disponibilidade, prazo e demais condições da obrigação anterior.
- **FR-016A**: Antes de confirmar a publicação, o sistema MUST informar separadamente quantos assignments ainda não iniciados e quantos assignments em progresso serão afetados.
- **FR-016B**: Assignments em progresso MUST permanecer na versão anterior por padrão, e o superadministrador MUST poder optar explicitamente por reiniciá-los na nova versão durante a publicação.
- **FR-016C**: Quando o reinício for escolhido, o sistema MUST cancelar os assignments em progresso anteriores com motivo auditável, criar obrigações equivalentes na nova versão e MUST NOT transferir o progresso anterior.
- **FR-016D**: Assignments concluídos, tentativas, evidências e certificados MUST permanecer vinculados à versão histórica concluída em todos os casos.
- **FR-017**: O sistema MUST impedir a publicação de um curso cuja composição contenha conteúdo sem versão publicada válida, indisponível ou inacessível ao respectivo owner.
- **FR-017A**: Ao publicar uma nova versão de módulo compartilhado, o sistema MUST criar e publicar automaticamente uma nova versão de cada curso publicado que utilize esse módulo, seja o curso compartilhado ou pertencente a uma empresa.
- **FR-017B**: A nova versão automática do curso MUST preservar todo o conteúdo da versão publicada anterior e substituir somente a referência do módulo atualizado pela nova versão compartilhada.
- **FR-017C**: A publicação automática das novas versões dos cursos MUST aplicar a cada assignment afetado as mesmas regras de substituição para não iniciados, permanência padrão para iniciados e preservação para concluídos.
- **FR-017D**: Se um curso afetado já possuir uma versão em rascunho, a versão automática MUST ser derivada da última versão publicada e MUST manter o rascunho existente separado, inalterado e não publicado.
- **FR-018**: Um superadministrador MUST poder promover um curso pertencente a uma empresa para conteúdo compartilhado após uma confirmação explícita das consequências.
- **FR-019**: Antes da promoção, o sistema MUST validar todo o curso, identificar todos os módulos próprios que terão ownership transferido e informar também os demais cursos afetados que já utilizam esses módulos.
- **FR-020**: A promoção MUST ser atômica: o curso e todos os seus módulos próprios MUST tornar-se compartilhados juntos, inclusive módulos reutilizados em outros cursos da empresa, ou nenhuma mudança é aplicada.
- **FR-021**: Ao promover um curso, o sistema MUST manter automaticamente sua associação com a empresa de origem.
- **FR-022**: A promoção MUST preservar identificadores, versões publicadas, assignments, tentativas, evidências, certificados e demais referências históricas existentes.
- **FR-023**: Após a promoção, somente a plataforma MUST poder editar ou criar versões futuras do conteúdo promovido; a empresa de origem permanece consumidora.
- **FR-024**: A reversão direta de um conteúdo compartilhado para ownership de uma empresa MUST ficar fora deste escopo; eventual cópia independente exige uma operação distinta.
- **FR-025**: Um superadministrador MUST poder arquivar conteúdo compartilhado para impedir novas associações, novas inclusões em composições e novos assignments sem apagar usos e históricos existentes.
- **FR-025A**: O arquivamento MUST NOT cancelar nem interromper assignments já existentes, que continuam vinculados à sua versão até conclusão, cancelamento ou substituição por outro fluxo autorizado.
- **FR-026**: Uma empresa MUST poder remover a associação de um curso compartilhado somente quando a remoção não invalidar obrigações ativas; em caso de impedimento, o sistema MUST explicar quais dependências precisam ser resolvidas.
- **FR-027**: A remoção de uma associação MUST afetar apenas a empresa solicitante e MUST preservar assignments, conclusões, evidências e certificados históricos.
- **FR-028**: Todas as criações, alterações de ciclo de vida, associações, remoções e promoções MUST gerar registro de auditoria com ator, momento, conteúdo e empresa afetada quando aplicável.
- **FR-029**: Cada ação grantable da empresa introduzida pela feature MUST possuir permissão atômica, respeitar pré-requisitos, ser protegida em navegação e acesso direto e permitir revogação imediata; superadministradores mantêm acesso exclusivo às ações da plataforma.
- **FR-030**: Buscas e seletores MUST retornar somente conteúdo compartilhado elegível para a ação e conteúdo pertencente à empresa ativa, sem expor conteúdo privado de outro tenant.
- **FR-031**: O sistema MUST usar `Shared Course`, `Shared Module`, `Company Course`, `Company Module`, `Browse Shared Courses` e `Add to Company` como nomenclatura de produto em inglês, com traduções localizadas; o termo `Import` MUST NOT representar uma simples associação.

### Key Entities

- **Curso**: Identidade permanente de um treinamento, com ownership da plataforma ou de uma única empresa, ciclo de vida e versões auditáveis.
- **Versão de curso**: Edição imutável após publicação, usada para congelar o conteúdo aplicável a assignments e certificados.
- **Módulo**: Unidade reutilizável de conteúdo de treinamento, pertencente à plataforma ou a uma única empresa e capaz de participar da composição de cursos elegíveis.
- **Versão de módulo**: Edição auditável do módulo que preserva o conteúdo utilizado por versões publicadas de cursos.
- **Composição do curso**: Relação ordenada entre uma versão de curso e as versões de módulos próprios ou compartilhados que formam o treinamento.
- **Associação da empresa ao curso**: Disponibilização explícita de um curso compartilhado para uma empresa sem cópia nem transferência de ownership.
- **Registro de auditoria**: Evidência imutável das ações de plataforma e empresa sobre ownership, associação e ciclo de vida.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Um superadministrador consegue criar e publicar um curso compartilhado completo pela área da plataforma em até 10 minutos, desconsiderando o tempo de upload e processamento de mídia.
- **SC-002**: Um administrador autorizado encontra e adiciona um curso compartilhado à empresa em até 3 minutos e no máximo 5 interações após abrir o catálogo.
- **SC-003**: 100% dos cursos e módulos exibidos nas áreas de plataforma e empresa apresentam ownership inequívoco e controles de edição compatíveis com esse ownership.
- **SC-004**: Testes com ao menos três empresas demonstram zero exposição ou alteração de conteúdo privado entre tenants em listagens, buscas, seletores e acessos diretos.
- **SC-005**: Uma única edição publicada de conteúdo compartilhado pode ser associada a pelo menos 100 empresas sem produzir cópias independentes do conteúdo.
- **SC-006**: 100% dos assignments e certificados anteriores a uma promoção, nova versão, arquivamento ou remoção de associação continuam vinculados à versão histórica correta.
- **SC-007**: Um administrador autorizado consegue compor e publicar um curso da empresa com módulos próprios e compartilhados sem precisar duplicar nenhum módulo compartilhado.
- **SC-008**: 100% das tentativas não autorizadas de editar conteúdo compartilhado ou acessar conteúdo privado de outra empresa são negadas sem alteração de dados.
- **SC-009**: Em teste de usabilidade, pelo menos 90% dos administradores identificam na primeira tentativa a diferença entre adicionar um curso compartilhado e criar um curso próprio.
- **SC-010**: Promoções, associações, remoções e mudanças de ciclo de vida deixam trilha de auditoria completa em 100% dos cenários testados.

## Assumptions

- `Shared` é a nomenclatura de produto; internamente, o conceito é conteúdo pertencente à plataforma em contraste com conteúdo pertencente à empresa.
- Adicionar um curso compartilhado a uma empresa cria somente uma associação. Duplicação independente, customização local de conteúdo compartilhado e sincronização de forks estão fora do escopo.
- Um curso compartilhado não fica disponível em uma empresa até que um administrador autorizado o associe explicitamente.
- A plataforma publica conteúdo para um catálogo comum a todas as empresas elegíveis; regras de distribuição seletiva por plano, região ou grupo de empresas ficam fora deste primeiro escopo.
- A promoção é iniciada e confirmada por um superadministrador. Administradores de empresa não podem transferir unilateralmente conteúdo para a plataforma.
- Quando um curso é promovido, todos os módulos próprios presentes em sua composição também se tornam compartilhados. Se um desses módulos for usado por outros cursos da empresa, esses cursos mantêm a referência e passam a consumir o módulo sob gestão da plataforma; a confirmação deve identificar claramente todos os cursos afetados.
- Conteúdos arquivados deixam de aceitar novas associações, novas composições e novos assignments, mas não são apagados; assignments já existentes continuam normalmente e suas evidências históricas permanecem disponíveis.
- A nova versão compartilhada passa a valer automaticamente para assignments ainda não iniciados. Assignments em progresso permanecem na versão anterior, salvo escolha explícita do superadministrador para reiniciá-los; conclusões históricas nunca são migradas.
- A publicação de uma nova versão de módulo compartilhado propaga automaticamente essa versão por meio de novas versões publicadas de todos os cursos que o utilizam; versões de curso anteriores nunca são alteradas.
- A interface usa inglês como idioma-fonte e mantém português somente nos arquivos de localização.
- A autenticação existente, a separação entre superadministradores e pessoas da empresa e a área `/platform` continuam sendo usadas.
