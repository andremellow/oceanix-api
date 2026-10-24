<td class="text-[#5f6a71]">{{ $person->departments->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-[#5f6a71]">{{ $person->jobFunctions->pluck('name')->join(', ') ?: '—' }}</td>
